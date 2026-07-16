<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;

/**
 * Hands recorded usage to the provider that bills it. The one seam between the package's own usage
 * ledger and a provider's meter.
 *
 * The `identifier` is the contract. It is minted once, when the usage is recorded, and replayed
 * unchanged on every retry — that is what makes a retry safe: the provider recognizes the identifier
 * and bills the usage once, however many times the network made us ask. An implementation must
 * therefore pass it through untouched and MUST NOT mint one of its own.
 *
 * `occurredAt` is when the usage HAPPENED, never when it was flushed, so a delayed flush still bills
 * the usage into the cycle it belongs to.
 */
interface UsageReporter
{
    /**
     * Report `quantity` units of `meterName` for a customer. Quantities are RAW — the allowance and the
     * packaging live in the provider's price, and applying them here as well would bill the customer
     * twice for neither.
     *
     * MUST be idempotent by identifier: reporting usage the provider already holds is a SUCCESS, and
     * must return normally. Providers signal this differently — Stripe rejects the replay outright with
     * `duplicate_meter_event` rather than accepting it quietly — so an implementation has to recognize
     * its provider's way of saying "already have it" and swallow it. Letting that surface as a failure
     * makes the flusher retry a report that can never succeed, and then declare revenue the provider has
     * already billed as lost.
     *
     * Throws only when the provider genuinely refused the usage; the caller then retries with the same
     * identifier.
     */
    public function report(
        string $customerReference,
        string $meterName,
        int $quantity,
        string $identifier,
        CarbonInterface $occurredAt,
    ): void;

    /**
     * Withdraw a reported event. Only possible while the cycle it belongs to is still open — once the
     * provider has finalized the invoice, a correction is a credit note, not a meter adjustment.
     */
    public function reverse(string $meterName, string $identifier): void;

    /**
     * The total the PROVIDER has actually recorded for this customer and meter in a window — the authority
     * our own ledger has to agree with.
     *
     * Reporting usage can succeed on our side and still never arrive: the ledger and the provider's meter are
     * two sources of truth that drift quietly, and the customer finds out on the invoice. This is the only
     * way to ask rather than assume. Null when the driver cannot answer (it bills usage locally and has no
     * remote meter to read back), which is not a drift — it is an absence of a second source.
     */
    public function recordedTotal(
        string $customerReference,
        string $meterName,
        CarbonInterface $from,
        CarbonInterface $to,
    ): ?int;
}
