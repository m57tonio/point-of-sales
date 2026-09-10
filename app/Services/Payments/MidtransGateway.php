<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

class MidtransGateway
{
    public function createCharge(Transaction $transaction, array $config): array
    {
        return $this->createTransaction($transaction, $config);
    }

    /**
     * Dynamic QRIS charge — Snap transaction restricted to QR-compatible
     * e-wallet payments; returns qr_string for on-counter rendering.
     */
    public function createQrisCharge(Transaction $transaction, array $config): array
    {
        $result = $this->createTransaction($transaction, $config, ['qris', 'gopay', 'shopeepay']);

        return [
            ...$result,
            'qr_string' => $result['raw']['qr_string'] ?? null,
        ];
    }

    private function createTransaction(Transaction $transaction, array $config, ?array $enabledPayments = null): array
    {
        if (! ($config['enabled'] ?? false)) {
            throw new PaymentGatewayException('Midtrans tidak aktif atau belum dikonfigurasi.');
        }

        $endpoint = $config['is_production'] ?? false
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $customer = $transaction->customer;

        $payload = [
            'transaction_details' => [
                'order_id' => $transaction->invoice,
                'gross_amount' => (int) $transaction->grand_total,
            ],
            'customer_details' => [
                'first_name' => optional($customer)->name ?? 'Customer',
                'email' => optional($customer)->email ?? config('mail.from.address'),
                'phone' => optional($customer)->no_telp,
            ],
            'callbacks' => [
                'finish' => route('transactions.print', $transaction->invoice),
            ],
        ];

        if ($enabledPayments !== null) {
            $payload['enabled_payments'] = $enabledPayments;
        }

        $response = Http::withBasicAuth($config['server_key'], '')
            ->post($endpoint, $payload);

        if ($response->failed()) {
            throw new PaymentGatewayException(
                'Midtrans error: '.$response->json('status_message', $response->body())
            );
        }

        return [
            'reference' => $response->json('order_id', $transaction->invoice),
            'payment_url' => $response->json('redirect_url'),
            'token' => $response->json('token'),
            'raw' => $response->json(),
        ];
    }
}
