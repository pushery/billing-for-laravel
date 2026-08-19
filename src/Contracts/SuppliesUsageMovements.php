<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\UsageMovement;

/**
 * A usage history that can also account for INDIVIDUAL movements, not only finished periods.
 *
 * ## Why this is its own contract and not a method on UsageHistoryProvider
 *
 * That interface invites consumers to bind their own — its own docblock says so — so a method added
 * there is a fatal error in code this package neither owns nor can fix. A sibling contract costs
 * nothing to ignore: a history either supplies movements or it does not, and the type system answers
 * that before anything runs. The same reasoning already governs {@see RoutesMoney} and
 * {@see SuppliesProductArchetypes}.
 *
 * A project without a movement-level ledger simply does not implement it, and its history screen stays
 * exactly as it is today. That is the right outcome rather than a fallback: periods and top-ups are a
 * complete answer for a project that only records those, and synthesizing movements from totals would
 * invent an ordering nobody recorded.
 *
 * ## What the aggregate cannot say
 *
 * `periods()` returns one row per period and `topups()` a separate list beside it. Both are true and
 * neither answers *why was I out on the 14th when I topped up on the 12th* — the ordering BETWEEN the
 * two lists is what explains the outcome, and putting them side by side is what removes it.
 *
 * ## Paginated, and including the period in progress
 *
 * `periods()` reports finished periods only; a movement stream is opened to look at what is happening
 * NOW, so the current period belongs in it. And a busy owner's month is longer than any fixed limit —
 * a paginator is what lets a screen page back through it without the contract guessing a depth.
 */
interface SuppliesUsageMovements
{
    /**
     * The owner's usage movements, newest first, across all meters.
     *
     * @return LengthAwarePaginator<int, UsageMovement>
     */
    public function movements(Model $owner, int $perPage = 20, int $page = 1): LengthAwarePaginator;
}
