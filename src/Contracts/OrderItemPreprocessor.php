<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\OrderItemDraft;

/**
 * A step that may reshape a cycle's lines before the order is written.
 *
 * A provider that prices usage remotely — Stripe, through meters — needs none of this: the amount to
 * collect comes back from the provider already correct. A local engine has no such source, so anything
 * beyond the flat plan price has to be computed here: metered consumption, a coupon, an application's own
 * per-cycle arithmetic. That is why the chain exists at all, and why its default is EMPTY: what a cycle
 * costs beyond its plan is a question only the consuming application can answer.
 *
 * Implementations are pure. They receive the lines decided so far and return the lines that should stand
 * — adding, removing, or rewriting. They do not write to the database, and they do not need to: the
 * engine writes what comes back, and the order's total is the sum of it.
 *
 * A step that throws aborts the cycle before anything is claimed. That is the intended behavior rather
 * than a gap: a half-priced order is worse than an unprocessed one, because the charge would go out
 * against a total nobody can reconstruct, and the next tick would find the cycle already claimed.
 */
interface OrderItemPreprocessor
{
    /**
     * The return type is a plain array, not a list, and that is deliberate: an implementation that filters
     * with `array_filter` returns gaps, which is the most natural way to write "drop this line" and would
     * otherwise be a contract violation nobody would expect. The chain reindexes what comes back.
     *
     * @param  list<OrderItemDraft>  $drafts  the lines decided so far, in the order they will be written
     * @return array<array-key, OrderItemDraft> the lines that should stand
     */
    public function handle(array $drafts, Subscription $subscription): array;
}
