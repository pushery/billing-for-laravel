<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Console\Commands\PruneBillingCommand;
use Pushery\Billing\Support\NoRetentionHolds;
use Pushery\Billing\Support\RetentionHoldGate;

/**
 * Which records a host forbids destroying, whatever the retention clock says.
 *
 * ## Why the clock alone is not enough
 *
 * {@see PruneBillingCommand} deletes on age: a window passes, the row goes. That is the storage-limitation
 * duty (GDPR Art. 5(1)(e)) and it is not optional. But a second duty can point the other way at the same
 * moment — litigation, a supervisory-authority request, a copyright dispute — and it is the HOST that knows
 * about it, because it is the host that received the letter. Without this seam a host has exactly two
 * positions, and both breach something: leave the pruner on and lose evidence it was ordered to preserve,
 * or switch it off and over-retain everything else. Neither is a policy.
 *
 * The damage from the first is also silent and unrecoverable. A deleted record does not announce itself; it
 * is missed at the moment somebody has to produce it, which is the moment it is most expensive.
 *
 * ## Bulk, deliberately
 *
 * The pruner deletes set-wise — one statement per table, not one per row. A per-record question would turn
 * that into one host call per expired row, and a purge whose cost is linear in the rows it is purging is a
 * purge that gets switched off. A host holding a hold table answers this with a single `whereIn`, which is
 * both cheaper and less code than the per-row loop; the reverse direction cannot be recovered efficiently.
 *
 * ## Fail-closed
 *
 * An implementation that cannot answer must throw. The pruner then deletes NOTHING from that table and the
 * run reports failure — see {@see RetentionHoldGate}. A hold seam that fell back to
 * "nothing is held" when it broke would be worse than no seam at all: it would delete exactly the records it
 * was installed to protect, at exactly the moment it was least able to say so.
 *
 * The shipped default is {@see NoRetentionHolds}, which holds nothing and therefore leaves the retention
 * behavior of an installation that binds nothing unchanged.
 */
interface RetentionHold
{
    /**
     * The subset of these records that may NOT be destroyed, however old they are.
     *
     * `$recordType` is the TABLE the records live in — the same name the retention matrix uses for its
     * rules — so a host can key its holds to something stable rather than to a class name that may be
     * swapped. Ids are whatever that table's primary key holds.
     *
     * Returning ids that were not asked about is harmless; the pruner only ever excludes from the set it
     * was about to delete.
     *
     * @param  string  $recordType  the table name, e.g. `billing_invoices`
     * @param  list<int|string>  $recordIds  the candidates the pruner is about to destroy
     * @return list<int|string> the ones it must leave alone
     */
    public function heldAmong(string $recordType, array $recordIds): array;
}
