<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\MerchantPayoutFailed;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Support\BillingEventLog;

/**
 * Writes a failed payout onto the merchant's own audit trail — the answer to "I have not been paid".
 *
 * ## Why the audit trail rather than a column
 *
 * There is no column this belongs in. A payout bundles many transfers, so it maps to no single recorded
 * sale, and inventing a per-payout table to hold one event per merchant per failure would be a
 * reconciliation ledger — a different piece of work, with a different subject.
 *
 * What an operator needs is the record that it happened, to whom, for how much, and what the provider said.
 * That is what the audit trail is, and it is already what a support agent reads.
 *
 * ## Idempotent by the payout id, and asserted on the RECORD rather than on a counter
 *
 * A redelivery states the same payout. Writing it twice would show a support agent two failures where there
 * was one, and "which of these is real" is a question with no answer in the data. So the effect looks for an
 * entry already carrying this payout id and returns.
 *
 * ## An account with no merchant on file is left alone
 *
 * It belongs to somebody else — another platform on the same provider, or an account this installation has
 * not onboarded. Recording it against the nearest merchant would put a stranger's failure in their history.
 */
final readonly class RecordFailedMerchantPayout
{
    public function __construct(
        private MerchantAccountDirectory $accounts,
        private BillingEventLog $log,
    ) {}

    public function __invoke(MerchantPayoutFailed $event): void
    {
        $merchant = $this->accounts->merchantForReference($event->accountReference);

        if (! $merchant instanceof Model) {
            return;
        }

        // Recognized by the PAYOUT id rather than by a count, because the question a redelivery raises is
        // "is this the same failure", and only the id answers it.
        //
        // Read back and compared in PHP rather than with a JSON-path predicate. This package is proven on
        // three engines, and a `payload->payout` predicate is written differently on each — the kind of
        // clause that passes the fast SQLite suite and behaves differently on the server a consumer runs.
        // The set is small by construction: it is one merchant's failed payouts, not the whole log.
        $recorded = BillingEvent::query()
            ->where('type', 'merchant.payout_failed')
            ->where('subject_type', $merchant->getMorphClass())
            ->where('subject_id', $merchant->getKey())
            ->pluck('payload');

        foreach ($recorded as $payload) {
            if (is_array($payload) && ($payload['payout'] ?? null) === $event->payoutReference) {
                return;
            }
        }

        $this->log->record('merchant.payout_failed', $merchant, [
            'provider' => $event->provider,
            'account' => $event->accountReference,
            'payout' => $event->payoutReference,
            'amount' => $event->amountMinor,
            'currency' => $event->currency,
            // Both halves of what the provider said. The code is what a report groups by; the sentence is
            // what a support agent reads to the merchant.
            'failure_code' => $event->failureCode,
            'failure_message' => $event->failureMessage,
        ], AuditSource::Webhook);
    }
}
