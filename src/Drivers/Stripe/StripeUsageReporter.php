<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\UsageReporter;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Reports usage into Stripe's billing meters, which rate it against the metered price on the
 * subscription and put it on the cycle's invoice.
 *
 * The identifier we minted at record time is passed straight through as Stripe's own `identifier`, so a
 * replayed report is deduped by Stripe rather than billed twice. Note HOW Stripe dedups: it does not
 * quietly accept the replay, it REJECTS it with `duplicate_meter_event`. That rejection means the usage
 * is already billed, so it is swallowed here as the success it is — the alternative is a flusher that
 * retries a report which can never succeed and then reports perfectly-billed revenue as lost.
 *
 * The timestamp is the moment the usage happened, so a flush delayed by an outage still lands in the
 * right cycle — Stripe accepts a back-dated event within its acceptance window, and past that window the
 * event is a lost charge, which is why the flusher escalates rather than retrying forever.
 *
 * The value crosses the wire RAW. Stripe's price does the packaging (`transform_quantity`) and the
 * allowance (a graduated first tier priced at zero) — doing either here as well would bill the customer
 * for a fraction of what they used, or hand them the free allowance twice.
 */
final readonly class StripeUsageReporter implements UsageReporter
{
    /** Stripe's answer to a replayed identifier: "I already have this event." */
    private const string DUPLICATE = 'duplicate_meter_event';

    public function __construct(private StripeClient $stripe) {}

    public function report(
        string $customerReference,
        string $meterName,
        int $quantity,
        string $identifier,
        CarbonInterface $occurredAt,
    ): void {
        try {
            $this->stripe->v2->billing->meterEvents->create([
                'event_name' => $meterName,
                'identifier' => $identifier,
                'timestamp' => $occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'payload' => [
                    'stripe_customer_id' => $customerReference,
                    'value' => (string) $quantity,
                ],
            ]);
        } catch (InvalidRequestException $e) {
            // Stripe does not dedup a replayed identifier silently — it REJECTS it with
            // `duplicate_meter_event`. That rejection is the success case: it means Stripe already holds
            // this usage and has billed it. Treating it as a failure would retry a report that can never
            // succeed, and then declare perfectly-billed revenue lost.
            if ($e->getStripeCode() !== self::DUPLICATE) {
                throw $e;
            }
        }
    }

    public function reverse(string $meterName, string $identifier): void
    {
        $this->stripe->v2->billing->meterEventAdjustments->create([
            'event_name' => $meterName,
            'type' => 'cancel',
            'cancel' => ['identifier' => $identifier],
        ]);
    }

    /**
     * What Stripe has actually AGGREGATED for this customer and meter in the window — the second source of
     * truth a reconcile compares our ledger against.
     *
     * Stripe addresses a meter's summaries by its ID, while everything else here speaks the meter's EVENT
     * NAME (the identifier usage is reported under), so the name is resolved to an id first. An event name
     * with no active meter behind it answers null: that is a configuration fault for `billing:meters:check`
     * to report, not a usage drift.
     */
    public function recordedTotal(
        string $customerReference,
        string $meterName,
        CarbonInterface $from,
        CarbonInterface $to,
    ): ?int {
        $meterId = $this->meterId($meterName);

        if ($meterId === null) {
            return null;
        }

        // Stripe requires the window to align to minute boundaries and rejects a second-precise timestamp
        // outright. A subscription cycle's start/end are second-precise, so the window is floored and ceiled
        // to the enclosing minutes — it can only widen by under a minute, which cannot pull in usage from an
        // adjacent cycle.
        $summaries = $this->stripe->billing->meters->allEventSummaries($meterId, [
            'customer' => $customerReference,
            'start_time' => intdiv($from->getTimestamp(), 60) * 60,
            'end_time' => intdiv($to->getTimestamp() + 59, 60) * 60,
        ]);

        $total = 0.0;

        foreach ($summaries->data as $summary) {
            $total += (float) ($summary->aggregated_value ?? 0);
        }

        return (int) round($total);
    }

    /** The id of the ACTIVE meter reporting under this event name, or null when there is none. */
    private function meterId(string $meterName): ?string
    {
        foreach ($this->stripe->billing->meters->all(['status' => 'active', 'limit' => 100])->data as $meter) {
            if (($meter->event_name ?? null) === $meterName) {
                $id = $meter->id ?? null;

                return is_string($id) ? $id : null;
            }
        }

        return null;
    }
}
