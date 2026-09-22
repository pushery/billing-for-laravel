<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ArrearsRoster;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\ArrearsEntry;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The roster as this package keeps it: the merchant-scoped subscription rows whose clock is running.
 *
 * This is the whole of what {@see PaymentReminderSweep} used to do inline, moved out so the sweep can be
 * pointed at somebody else's storage without being rewritten. The query is unchanged, and the two paragraphs
 * it carried about WHY it selects that way moved with it rather than being summarized.
 *
 * ## Merchant rows only, and that is not the same as reading the marketplace flag
 *
 * The cure window is a marketplace mechanism: it exists because a customer holds several subscriptions to
 * several merchants and loses only the ones in arrears. A single-seller install keeps the dunning ladder,
 * where nothing expires after a week — so a reminder counting down "5 days left" there would name a deadline
 * that never arrives, which is worse than silence.
 *
 * The narrower test is the right one: it stays byte-identical in a single-seller install whatever the flag
 * does, and it keeps an install that switches the flag on from retroactively pulling its own platform
 * subscriptions into a rule they were never sold under.
 */
final readonly class LocalArrearsRoster implements ArrearsRoster
{
    /** @return iterable<ArrearsEntry> */
    public function inArrearsSince(DateTimeInterface $after, string $onDay): iterable
    {
        $due = Subscription::model()::query()
            ->merchantScoped()
            ->whereNotNull('delinquent_since')
            ->where('delinquent_since', '>', $after)
            ->where(function (Builder $query) use ($onDay): void {
                $query->whereNull('payment_reminded_on')
                    ->orWhereDate('payment_reminded_on', '<', $onDay);
            })
            ->with('owner')
            ->orderBy('id')
            ->get();

        foreach ($due as $subscription) {
            $owner = $subscription->owner;

            // A row whose owner has gone is skipped rather than crashing the sweep. It is reachable: the
            // erasure path unlinks a retained document from its owner, and a half-migrated install can leave
            // a dangling morph. There is nobody to send a message to either way, and one such row must not
            // stop every later reminder in the run.
            if (! $owner instanceof Model) {
                continue;
            }

            // The query already filtered on a non-null clock, so the coalesce is a type narrowing rather
            // than a fallback — and written as one statement rather than a guard, because a branch that
            // cannot be reached is a branch no test can cover honestly. The sweep it was lifted from made
            // the same call for the same reason.
            $since = $subscription->delinquent_since ?? CarbonImmutable::now();

            yield new ArrearsEntry(
                owner: $owner,
                merchant: MerchantScope::fromUid((string) $subscription->merchant_uid),
                since: $since,
                // The typed property rather than `getKey()`, which the model declares as mixed.
                key: $subscription->id,
                subscription: $subscription,
            );
        }
    }

    public function markReminded(ArrearsEntry $entry, string $onDay): void
    {
        $subscription = $entry->subscription;

        if ($subscription instanceof Subscription) {
            $subscription->forceFill(['payment_reminded_on' => $onDay])->save();

            return;
        }

        // The entry came from somewhere else, so fall back to its key. Reached only if somebody hands this
        // roster an entry it did not produce, which is not a normal path but is a cheap one to survive.
        Subscription::model()::query()->whereKey($entry->key)->update(['payment_reminded_on' => $onDay]);
    }
}
