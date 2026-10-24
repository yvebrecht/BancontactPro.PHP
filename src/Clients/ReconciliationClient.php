<?php

declare(strict_types=1);

namespace BancontactPro\Clients;

use BancontactPro\Http\Transport;
use BancontactPro\Json\Hydrator;
use BancontactPro\Models\PayoutListResponse;
use BancontactPro\Models\PayoutPaymentsResponse;
use BancontactPro\Models\PayoutRefundsResponse;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Reconciliation API v3. Requests are signed with the merchant's key.
 *
 * `$size` is fixed by the spec (minimum, maximum and default are all 10000); it is exposed in case that changes.
 * Dates are calendar dates (`yyyy-MM-dd`); the time part of a `DateTimeInterface` is ignored.
 */
final class ReconciliationClient
{
    public function __construct(private readonly Transport $transport) {}

    /** `GET v3/reconciliation/payouts`. `$date` defaults server-side to the current UTC date. */
    public function getPayouts(?DateTimeInterface $date = null, ?int $page = null, ?int $size = null): PayoutListResponse
    {
        $params = [];
        if ($date !== null) {
            $params[] = 'date=' . $date->format('Y-m-d');
        }
        Query::paging($params, $page, $size);

        return Hydrator::hydrate(
            PayoutListResponse::class,
            $this->transport->request('GET', Query::append('v3/reconciliation/payouts', $params)),
        );
    }

    /** `GET v3/reconciliation/payments`, by payout id or by a date range of at most 30 days. */
    public function getPayments(
        ?string $payoutId = null,
        ?DateTimeInterface $startDate = null,
        ?DateTimeInterface $endDate = null,
        ?int $page = null,
        ?int $size = null,
    ): PayoutPaymentsResponse {
        $params = self::payoutFilter($payoutId, $startDate, $endDate);
        Query::paging($params, $page, $size);

        return Hydrator::hydrate(
            PayoutPaymentsResponse::class,
            $this->transport->request('GET', Query::append('v3/reconciliation/payments', $params)),
        );
    }

    /** `GET v3/reconciliation/refunds`, by payout id or by a date range of at most 30 days. */
    public function getRefunds(
        ?string $payoutId = null,
        ?DateTimeInterface $startDate = null,
        ?DateTimeInterface $endDate = null,
        ?int $page = null,
        ?int $size = null,
    ): PayoutRefundsResponse {
        $params = self::payoutFilter($payoutId, $startDate, $endDate);
        Query::paging($params, $page, $size);

        return Hydrator::hydrate(
            PayoutRefundsResponse::class,
            $this->transport->request('GET', Query::append('v3/reconciliation/refunds', $params)),
        );
    }

    /** @return list<string> */
    private static function payoutFilter(?string $payoutId, ?DateTimeInterface $startDate, ?DateTimeInterface $endDate): array
    {
        if (($startDate === null) !== ($endDate === null)) {
            throw new InvalidArgumentException('startDate and endDate must be given together.');
        }
        $params = [];
        if ($payoutId !== null) {
            $params[] = 'payout-id=' . Transport::escape($payoutId);
        }
        if ($startDate !== null && $endDate !== null) {
            $start = new DateTimeImmutable($startDate->format('Y-m-d') . 'T00:00:00Z');
            $end = new DateTimeImmutable($endDate->format('Y-m-d') . 'T00:00:00Z');
            if ($start->diff($end)->days > 30) {
                throw new InvalidArgumentException('The range between startDate and endDate can be at most 30 days.');
            }
            $params[] = 'start-date=' . $start->format('Y-m-d');
            $params[] = 'end-date=' . $end->format('Y-m-d');
        }

        return $params;
    }
}
