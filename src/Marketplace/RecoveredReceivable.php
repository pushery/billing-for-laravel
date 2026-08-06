<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Which write-offs a later payment could still reopen.
 *
 * A correction issued because the consideration would not be received is a judgement about the future, and
 * the future is allowed to disagree. When the money turns up anyway, the write-off was wrong and the tax has
 * to go back. A correction issued because the consideration was HANDED BACK can never be reopened — a
 * payment afterwards is a new transaction with its own document.
 *
 * The two produce identical figures in identical periods, so nothing in the amounts can tell them apart
 * afterwards. This is what the reason on the document is for, and this class is what reads it: a write-off
 * that is still provisional is an open item, and an open item nobody can list is one nobody reviews.
 *
 * Fail-closed on silence. A correction that states no reason is NOT treated as reopenable — assuming
 * otherwise would put a sale back on the strength of a missing field.
 */
final readonly class RecoveredReceivable
{
    /** Whether a later payment against this correction reopens it, rather than starting something new. */
    public function reversible(InvoiceRecord $correction): bool
    {
        return $correction->tax_base_change_reason instanceof TaxBaseChangeReason
            && $correction->tax_base_change_reason->reversesOnLaterReceipt();
    }

    /**
     * Every correction still standing on the judgement that the money will not arrive.
     *
     * Ordered oldest first, because age is what the review turns on: a write-off nobody has revisited in a
     * year is either right and settled or wrong and overdue, and both need somebody to look.
     *
     * @return list<InvoiceRecord>
     */
    public function provisionalWriteOffs(string $ownerType, int|string $ownerId): array
    {
        /** @var list<InvoiceRecord> $rows */
        $rows = InvoiceRecord::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('tax_base_change_reason', TaxBaseChangeReason::Uncollectible->value)
            ->orderBy('issued_at')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * The same question across every merchant — what the books still carry as provisional.
     *
     * @return list<InvoiceRecord>
     */
    public function allProvisionalWriteOffs(): array
    {
        /** @var list<InvoiceRecord> $rows */
        $rows = InvoiceRecord::query()
            ->where(function (Builder $query): void {
                $query->where('tax_base_change_reason', TaxBaseChangeReason::Uncollectible->value);
            })
            ->orderBy('issued_at')
            ->get()
            ->all();

        return $rows;
    }
}
