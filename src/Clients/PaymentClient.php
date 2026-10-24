<?php

declare(strict_types=1);

namespace BancontactPro\Clients;

use BancontactPro\Http\Transport;
use BancontactPro\Json\Hydrator;
use BancontactPro\Models\CreatePaymentRequest;
use BancontactPro\Models\CreatePaymentResponse;
use BancontactPro\Models\CreateStaticQrPaymentRequest;
use BancontactPro\Models\DebtorRefundIban;
use BancontactPro\Models\Payment;
use BancontactPro\Models\PaymentAcknowledgeRequest;
use BancontactPro\Models\PaymentSearchQuery;
use BancontactPro\Models\PaymentSearchResponse;

/** Payment API v3: online, VOID-mode and static QR payments. Authenticated by the product's API key. */
final class PaymentClient
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * `POST v3/payments`.
     *
     * If the call times out the outcome is unknown: Bancontact may have created the payment. Do not retry blindly;
     * look the payment up by the reference you sent with `search` first.
     */
    public function create(CreatePaymentRequest $request): CreatePaymentResponse
    {
        return Hydrator::hydrate(CreatePaymentResponse::class, $this->transport->request('POST', 'v3/payments', $request));
    }

    /** `GET v3/payments/{id}`: the documented polling fallback. */
    public function get(string $paymentId): Payment
    {
        return Hydrator::hydrate(Payment::class, $this->transport->request('GET', 'v3/payments/' . Transport::escape($paymentId)));
    }

    /** `DELETE v3/payments/{id}`, allowed while the payment is PENDING or IDENTIFIED. */
    public function cancel(string $paymentId): void
    {
        $this->transport->request('DELETE', 'v3/payments/' . Transport::escape($paymentId));
    }

    /** `POST v3/payments/search`. `$page` is zero-based (default 0); `$size` max 100 (default 10). */
    public function search(?PaymentSearchQuery $query = null, ?int $page = null, ?int $size = null): PaymentSearchResponse
    {
        $params = [];
        Query::paging($params, $page, $size);

        return Hydrator::hydrate(
            PaymentSearchResponse::class,
            $this->transport->request('POST', Query::append('v3/payments/search', $params), $query ?? new PaymentSearchQuery()),
        );
    }

    /** `POST v3/payments/{id}/acknowledge` for the VOID-mode flow (PENDING_MERCHANT_ACKNOWLEDGEMENT). */
    public function acknowledge(string $paymentId, PaymentAcknowledgeRequest $request): void
    {
        $this->transport->request('POST', 'v3/payments/' . Transport::escape($paymentId) . '/acknowledge', $request);
    }

    /** `GET v3/payments/{id}/debtor/refundIban`. Requires the payment to be SUCCEEDED. */
    public function getDebtorRefundIban(string $paymentId): DebtorRefundIban
    {
        return Hydrator::hydrate(
            DebtorRefundIban::class,
            $this->transport->request('GET', 'v3/payments/' . Transport::escape($paymentId) . '/debtor/refundIban'),
        );
    }

    /** `POST v3/payments/pos`. Replaces the till's active payment; a timed-out call has an unknown outcome. */
    public function createStaticQrPayment(CreateStaticQrPaymentRequest $request): CreatePaymentResponse
    {
        return Hydrator::hydrate(CreatePaymentResponse::class, $this->transport->request('POST', 'v3/payments/pos', $request));
    }
}
