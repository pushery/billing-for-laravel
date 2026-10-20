<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The daily caps on what model calls may cost: one per provider, against the margin, and one per owner, against abuse.
 *
 * `billing.ai.daily_caps` names a cap per provider and `billing.ai.owner_daily_cap` one for every owner, both in
 * `billing.ai.currency`. A provider without a cap, or a cost in another currency, is counted and never refused.
 *
 * The check and the count are one statement. {@see self::spend()} raises an account only where the raise stays at or
 * below its cap, so two calls at the cap cannot both pass it: the second statement waits for the first one's row
 * lock and then evaluates its condition against what the first one committed. Days are counted in UTC, the day a
 * provider bills by.
 *
 * A cost known only after the call has already been spent, so {@see self::record()} counts it whatever the cap says,
 * and an account it takes past its cap is full: {@see self::isFull()} answers that before the next call.
 */
final readonly class SpendBrake
{
    private const string TABLE = 'billing_ai_spend';

    public function __construct(private Repository $config) {}

    /**
     * Count a cost against the provider's account and, where one is given, the owner's, if it fits under both caps.
     *
     * Both or neither: a cost the owner's cap refuses is not left on the provider's account. Call it with what a call
     * may cost before making it. A cost that has already been spent goes to {@see self::record()} instead, because a
     * refusal here would leave it uncounted and the account under its cap. A cost of nothing counts nothing and is
     * never refused.
     *
     * @return bool whether the cost was counted; false means a cap refused it and nothing was counted
     */
    public function spend(string $provider, AiCost $cost, ?Model $owner = null): bool
    {
        if ($cost->micros === 0) {
            // Nothing to count. A raise by nothing would change no row either, which MySQL reports as no row matched.
            return true;
        }

        $day = CarbonImmutable::now('UTC')->toDateString();

        // Two first calls of a day can meet where the day's row is still missing, and MySQL then rolls one of them back
        // as a deadlock; the next attempt finds the row the other one wrote.
        return DB::transaction(function () use ($provider, $cost, $owner, $day): bool {
            $raised = [];

            foreach ($this->accounts($provider, $owner) as [$account, $cap, $ownerOf]) {
                if (! $this->raise($account, $ownerOf, $day, $cost, $cap)) {
                    // Refused. What this call raised on the accounts before is taken off again; their rows are still
                    // locked by that raise, so nobody counted on them in between.
                    foreach ($raised as $earlier) {
                        $this->row($earlier, $day, $cost->currency)->decrement('spent_micros', $cost->micros);
                    }

                    return false;
                }

                $raised[] = $account;
            }

            return true;
        }, 3);
    }

    /**
     * Count a cost that has already been spent against the provider's account and, where one is given, the owner's,
     * whatever their caps say.
     *
     * Nothing is refused: the money is gone, and leaving it uncounted would keep the account under its cap for every
     * later call. An account this takes to or past its cap is full, and {@see self::isFull()} says so before the next
     * call. A cost of nothing counts nothing.
     */
    public function record(string $provider, AiCost $cost, ?Model $owner = null): void
    {
        if ($cost->micros === 0) {
            return;
        }

        $day = CarbonImmutable::now('UTC')->toDateString();

        DB::transaction(function () use ($provider, $cost, $owner, $day): void {
            foreach ($this->accounts($provider, $owner) as [$account, , $ownerOf]) {
                $this->raise($account, $ownerOf, $day, $cost, null);
            }
        }, 3);
    }

    /**
     * Whether a call would meet a cap today: the provider's account, or the owner's where one is given, has counted up
     * to its cap or past it. An account without a cap is never full.
     */
    public function isFull(string $provider, ?Model $owner = null): bool
    {
        foreach ($this->accounts($provider, $owner) as [, $cap, $ownerOf]) {
            if ($cap !== null && $this->spent($provider, $ownerOf)->micros >= $cap) {
                return true;
            }
        }

        return false;
    }

    /**
     * The daily cap of the account the arguments name, the owner's where an owner is given and the provider's otherwise,
     * in the brake's currency; null for an account without one. The same account {@see self::spent()} reads.
     */
    public function capFor(string $provider, ?Model $owner = null): ?AiCost
    {
        $cap = null;

        // The accounts come provider first, so the last one is the owner's where an owner is given.
        foreach ($this->accounts($provider, $owner) as [, $accountCap]) {
            $cap = $accountCap;
        }

        return $cap === null ? null : new AiCost($cap, $this->currency());
    }

    /**
     * What the providers' accounts counted, day by day from the oldest: every provider, or the one given, and from a day
     * on where one is given. An operator's view of the spend reads this rather than the brake's table.
     *
     * @return list<SpendDay>
     */
    public function history(?string $provider = null, ?CarbonInterface $from = null): array
    {
        $query = DB::table(self::TABLE);

        if ($provider === null) {
            $query->where('account', 'like', 'provider:%');
        } else {
            $query->where('account', 'provider:'.$provider);
        }

        return $this->daysOf($query, $from);
    }

    /**
     * What an owner's account counted, day by day from the oldest, from a day on where one is given. An owner's account
     * counts the owner's calls to every provider together, so its days name no provider.
     *
     * @return list<SpendDay>
     */
    public function ownerHistory(Model $owner, ?CarbonInterface $from = null): array
    {
        return $this->daysOf(DB::table(self::TABLE)->where('account', $this->ownerAccount($owner)), $from);
    }

    /** What a provider's account, or an owner's, has counted on a day in the brake's currency. */
    public function spent(string $provider, ?Model $owner = null, ?CarbonInterface $day = null): AiCost
    {
        $account = $owner instanceof Model ? $this->ownerAccount($owner) : 'provider:'.$provider;
        $spent = DB::table(self::TABLE)
            ->where('account', $account)
            ->where('day', CarbonImmutable::instance($day ?? CarbonImmutable::now('UTC'))->utc()->toDateString())
            ->where('currency', $this->currency())
            ->value('spent_micros');

        return new AiCost(is_numeric($spent) ? (int) $spent : 0, $this->currency());
    }

    /**
     * The accounts a cost is counted on, the provider's first, always in that order, so two calls never take the two
     * rows the other way round.
     *
     * @return list<array{0: string, 1: ?int, 2: ?Model}>
     */
    private function accounts(string $provider, ?Model $owner): array
    {
        $caps = $this->config->get('billing.ai.daily_caps');
        $providerCap = is_array($caps) && array_key_exists($provider, $caps)
            ? Micros::parse($caps[$provider], "billing.ai.daily_caps[{$provider}]")
            : null;

        $accounts = [['provider:'.$provider, $providerCap, null]];

        if ($owner instanceof Model) {
            $ownerCap = $this->config->get('billing.ai.owner_daily_cap');
            $accounts[] = [$this->ownerAccount($owner), $ownerCap === null ? null : Micros::parse($ownerCap, 'billing.ai.owner_daily_cap'), $owner];
        }

        return $accounts;
    }

    /**
     * Raise one account of the day by a cost where its cap leaves room for it, and say whether it did.
     *
     * The raise comes first. Where it raised nothing, either the cap refused the cost or the account has no row for
     * the day yet, and only in the second case is the row written and the raise repeated. On a row that exists the
     * raise is the one statement that writes it, and it takes the row's lock outright: a write that looked for the row
     * first would leave a shared lock on it that a second call holds as well, and on MySQL the two raises would each
     * wait for the other.
     */
    private function raise(string $account, ?Model $owner, string $day, AiCost $cost, ?int $cap): bool
    {
        if ($this->raiseWithinCap($account, $day, $cost, $cap) > 0) {
            return true;
        }

        if ($this->row($account, $day, $cost->currency)->exists()) {
            return false;
        }

        $this->open($account, $owner, $day, $cost->currency);

        return $this->raiseWithinCap($account, $day, $cost, $cap) > 0;
    }

    /** The conditional raise itself, one statement: how many rows it raised, one or none. */
    private function raiseWithinCap(string $account, string $day, AiCost $cost, ?int $cap): int
    {
        $raise = $this->row($account, $day, $cost->currency);

        if ($cap !== null && $cost->currency === $this->currency()) {
            $raise->where('spent_micros', '<=', $cap - $cost->micros);
        }

        return $raise->increment('spent_micros', $cost->micros);
    }

    /** The account's row for the day, written where it is missing, so the raise has a row to take. */
    private function open(string $account, ?Model $owner, string $day, string $currency): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'account' => $account,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'day' => $day,
            'currency' => $currency,
            'spent_micros' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The rows a query selects as days, the oldest first, and within a day in account and then currency order.
     *
     * @return list<SpendDay>
     */
    private function daysOf(Builder $query, ?CarbonInterface $from): array
    {
        if ($from instanceof CarbonInterface) {
            $query->where('day', '>=', CarbonImmutable::instance($from)->utc()->toDateString());
        }

        $days = [];

        foreach ($query->orderBy('day')->orderBy('account')->orderBy('currency')->get(['account', 'day', 'currency', 'spent_micros']) as $row) {
            $account = is_string($row->account ?? null) ? $row->account : '';
            $day = is_string($row->day ?? null) ? $row->day : '';
            $currency = is_string($row->currency ?? null) ? $row->currency : $this->currency();
            $micros = is_numeric($row->spent_micros ?? null) ? (int) $row->spent_micros : 0;

            $days[] = new SpendDay(
                str_starts_with($account, 'provider:') ? substr($account, strlen('provider:')) : null,
                CarbonImmutable::parse($day, 'UTC')->startOfDay(),
                new AiCost($micros, $currency),
            );
        }

        return $days;
    }

    private function row(string $account, string $day, string $currency): Builder
    {
        return DB::table(self::TABLE)->where('account', $account)->where('day', $day)->where('currency', $currency);
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.ai.currency', 'USD');

        return is_string($currency) ? $currency : 'USD';
    }

    private function ownerAccount(Model $owner): string
    {
        $key = $owner->getKey();

        return 'owner:'.$owner->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '');
    }
}
