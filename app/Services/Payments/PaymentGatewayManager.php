<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentSetting;
use App\Models\Transaction;

class PaymentGatewayManager
{
    public function __construct(
        private MidtransGateway $midtransGateway,
        private XenditGateway $xenditGateway
    ) {}

    public function createPayment(Transaction $transaction, string $gateway, PaymentSetting $setting): array
    {
        return match ($gateway) {
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransGateway->createCharge($transaction, $setting->midtransConfig()),
            PaymentSetting::GATEWAY_XENDIT => $this->xenditGateway->createInvoice($transaction, $setting->xenditConfig()),
            default => throw new PaymentGatewayException("Gateway {$gateway} belum didukung."),
        };
    }

    /**
     * Dynamic QRIS charge — uses whichever gateway is ready (midtrans
     * preferred, xendit fallback). Returns reference/payment_url/qr_string.
     */
    public function createQrisPayment(Transaction $transaction, PaymentSetting $setting): array
    {
        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS)) {
            return $this->midtransGateway->createQrisCharge($transaction, $setting->midtransConfig());
        }

        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_XENDIT)) {
            return $this->xenditGateway->createQrisInvoice($transaction, $setting->xenditConfig());
        }

        throw new PaymentGatewayException('Tidak ada gateway aktif untuk QRIS. Aktifkan Midtrans atau Xendit di pengaturan pembayaran.');
    }
}
