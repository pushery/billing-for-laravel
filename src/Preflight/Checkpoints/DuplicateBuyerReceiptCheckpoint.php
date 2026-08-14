<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * Two buyer receipts over ONE sale, which is a document a return counts twice.
 *
 * ## What can produce them
 *
 * A periodless receipt is deduplicated by READING before it writes — the issuer looks for a document on the
 * same charge reference and hands that one back. That removes the ordinary repeat, which is a redelivery
 * arriving after the first has been written, and it is what providers actually do.
 *
 * Two deliveries arriving at the SAME instant are the case it cannot see. Both read, both find nothing, both
 * write, and each draws its own number — the sequence allocator locks, so the numbers are distinct and the
 * series stays gapless. The damage is therefore not a hole in the numbering. It is a **duplicate**: a buyer
 * holds two receipts for one purchase, and the sale is declared twice.
 *
 * ## Why this asks rather than fixes
 *
 * The fix is a unique index, and an index cannot be created on a table that already violates it. On an
 * installation carrying such a pair the migration would fail — at the adopter's end, mid-upgrade, with a
 * constraint error naming a column rather than a sale. That is the worst place to discover it.
 *
 * So the question is asked HERE, where the answer is a report and a list of document numbers instead of a
 * failed migration. It is not blocking: the duplicate is rare, it costs no money, and a marketplace should
 * not be stopped from going live over it. It must not be silent either, which is the whole point.
 *
 * ## The key it counts on
 *
 * `(owner, series, settled_charge_reference)` over the documents that CLAIM their reference — the sale's
 * first ones. A reissue, a correction and anything carrying a period are excluded by the columns that mark
 * them, which is the same definition `billing_invoices_owner_series_charge_unique` is derived from, so a
 * pass here is a genuine prediction that the migration will apply rather than a related-looking check.
 *
 * The roles, not the TIER, and that is a correction rather than a preference. Grouping with the tier reads
 * correctly for a reissue — it does carry a different one — but it makes every CORRECTION share a group,
 * because a correction carries no tier at all. Two partial refunds of one sale are two corrections, both
 * legitimate, and the tier key reported them as a duplicate. The marker columns say what each document IS
 * instead of inferring it from a field that happens to differ.
 *
 * The provider is deliberately NOT in the key, even though the INDEX has it. That makes this check
 * deliberately WIDER than the constraint: every pair the index would refuse is reported here, plus a pair
 * that differs only in whether a provider was recorded — which the index accepts. A charge reference is
 * unique only per provider, so on the face of it the provider belongs here, but a mixed population of null
 * and named rows is the permanent state of an installation that adopted provider recording partway, and
 * grouping on the column splits two documents about one sale into two groups that each look fine.
 *
 * The two errors are not the same size. This checkpoint is a warning: it does not block and it can be
 * waived, so a false positive costs an operator a look. A second numbered document over one sale, missed,
 * costs a gap in a series that must not have one — and the whole reason the check exists is that the
 * eventual unique index cannot be created on data that already breaks it.
 *
 * A buyer who asks for a proper invoice legitimately receives a SECOND document over the same sale, and a
 * correction likewise shares the reference — as does every further correction after it. All of them are
 * documents that must exist, and each says so on itself: `reissue_of_invoice_id`, `credited_invoice_id`,
 * `settlement_period`. Only two documents that each claim to be the sale's FIRST are the duplicate.
 *
 * A row with no provider recorded groups with the other rows that have none, which is right: they were all
 * written by an installation that recorded no provider, so they genuinely share one namespace.
 */
final readonly class DuplicateBuyerReceiptCheckpoint implements GoLiveCheckpoint
{
    /** How many offending references to name before the message stops listing them. */
    private const int SAMPLE = 5;

    public function key(): string
    {
        return 'duplicate_buyer_receipts';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return false;
    }

    public function isWaivable(): bool
    {
        return true;
    }

    public function evaluate(): CheckpointOutcome
    {
        $offenders = DB::table('billing_invoices')
            ->select('settled_charge_reference', 'document_series')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('settled_charge_reference')
            // The three roles that legitimately share a reference, excluded by the columns that mark them
            // rather than inferred from the tier. Read on the ROWS instead of on the claim column, on
            // purpose: a row written before that column existed carries null there, and those rows are
            // exactly the population this checkpoint is for.
            ->whereNull('reissue_of_invoice_id')
            ->whereNull('credited_invoice_id')
            ->whereNull('settlement_period')
            ->groupBy('owner_type', 'owner_id', 'document_series', 'settled_charge_reference')
            ->havingRaw('COUNT(*) > 1')
            ->limit(self::SAMPLE + 1)
            ->get();

        if ($offenders->isEmpty()) {
            return CheckpointOutcome::pass(
                'No sale carries two buyer documents that each claim to be its first. '
                .'billing_invoices_owner_series_charge_unique applies to this installation, which is the '
                .'check behind this answer rather than a separate one.'
            );
        }

        // Filtered to strings rather than cast, and the difference matters: the column is nullable, and a
        // cast would turn a null that slipped past the `whereNotNull` into an empty entry in a list an
        // operator is meant to search by. Nothing there is better than an empty name.
        $named = $offenders->take(self::SAMPLE)
            ->map(static fn (object $row): mixed => $row->settled_charge_reference)
            ->filter(static fn (mixed $reference): bool => is_string($reference) && $reference !== '')
            ->implode(', ');

        $more = $offenders->count() > self::SAMPLE ? ' and more' : '';

        return CheckpointOutcome::warn(
            'Some sales carry two buyer documents that each claim to be the first, which means the sale was '
            .'declared '
            .'twice and the buyer holds two receipts for one purchase. Charge references: '.$named.$more.'. '
            .'Nothing is lost and no money moved wrongly — but these have to be corrected before a unique '
            .'index can hold the rule, because an index cannot be created on data that already breaks it. '
            .'Cancel the surplus document through the ordinary correction path rather than deleting the row: '
            .'a document that was issued stays issued.'
        );
    }
}
