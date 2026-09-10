<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosTransactionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPosFixtures();
    }

    private function setUpPosFixtures(): void
    {
        $this->cashier = User::factory()->create();
        $this->warehouse = Warehouse::create([
            'code' => 'WH-SYNC',
            'name' => 'Gudang Sync',
            'status' => 'active',
        ]);
        $this->category = Category::create([
            'name' => 'Kategori Sync',
            'image' => '',
            'description' => '',
        ]);
        $this->product = Product::create([
            'title' => 'Produk Sync',
            'barcode' => 'SYNC-001',
            'sku' => 'SKU-SYNC-001',
            'image' => '',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 50,
            'category_id' => $this->category->id,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
            'min_stock' => 0,
            'max_stock' => 100,
            'is_composite' => false,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);
    }

    private function syncPayload(array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'items' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_id' => null],
            ],
            'cash' => 100000,
        ], $overrides);
    }

    public function test_sync_creates_transaction_with_server_side_pricing(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id
        );

        // Client claims grand_total 1 — server must ignore it.
        $response = $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$this->syncPayload(['grand_total' => 1])],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'synced');

        $transaction = Transaction::first();
        $this->assertNotNull($transaction);
        $this->assertEquals(20000, $transaction->grand_total);
        $this->assertEquals(
            '550e8400-e29b-41d4-a716-446655440000',
            $transaction->client_uuid
        );
        $this->assertSame(1, $transaction->details()->count());
        $this->assertEquals(48, $this->product->fresh()->stock);
    }

    public function test_sync_is_idempotent_by_client_uuid(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id
        );

        $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$this->syncPayload()],
        ])->assertOk();

        $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$this->syncPayload()],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');

        $this->assertSame(1, Transaction::count());
        $this->assertEquals(48, $this->product->fresh()->stock);
    }

    public function test_sync_fails_without_active_shift_and_keeps_no_data(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $response = $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$this->syncPayload()],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Cart::count());
        $this->assertEquals(50, $this->product->fresh()->stock);
    }

    public function test_sync_requires_authentication(): void
    {
        $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$this->syncPayload()],
        ])->assertUnauthorized();
    }

    public function test_sync_validates_payload_structure(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [
                ['client_uuid' => 'not-a-uuid', 'items' => []],
            ],
        ])->assertUnprocessable();
    }
}
