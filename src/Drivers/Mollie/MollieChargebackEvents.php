<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Resources\Chargeback;
use Pushery\Billing\Enums\ChargebackOutcome;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentChargeback;
use stdClass;
use Throwable;

/**
 * Turns Mollie's chargeback collection into neutral events, one per chargeback.
 *
 * Its own class rather than a private method on the mapper, and the reason is testability of the guards
 * rather than tidiness. The SDK's collections extend `ArrayObject` and do not guarantee their element
 * type, so what they hold is an untyped boundary — but the SDK's own hydration always produces a
 * `Chargeback` whatever the payload said, which makes the narrowing impossible to exercise through it. A
 * guard no run can enter is indistinguishable from a guard that is wrong, so the conversion is exposed
 * where a caller can hand it the shapes the type system admits.
 *
 * The event is filled the way every driver fills it: `reference` names the payment, which is what access,
 * the purchase and the documents hang on, and `disputeReference` names the chargeback, which is what keeps
 * two chargebacks on one payment apart.
 *
 * Three things it will not do, and all three are about money:
 *
 * **It never reports the payment's amount.** A chargeback is not necessarily the whole payment — partial
 * ones exist — so the amount comes from the chargeback itself or the entry is skipped.
 *
 * **It does not report a chargeback that was reversed.** The money came back to the merchant, and an event
 * raised for it would take back access and credit for a dispute that is over.
 *
 * **One malformed entry does not take the others with it.** The isolation is per item, because a single
 * unreadable row aborting the loop would lose every other chargeback beside it and, upstream, the
 * payment's own success — which came from an entirely different call. The entry it skips is named in a
 * warning, since a chargeback left out of the figures without a word is money gone that nobody can find.
 */
final readonly class MollieChargebackEvents
{
    /**
     * @param  iterable<mixed>  $chargebacks
     * @return iterable<ChargebackReceived>
     */
    public static function from(iterable $chargebacks, string $customerReference, string $paymentReference): iterable
    {
        foreach (self::standingWithAmounts($chargebacks) as [$chargeback, $amount]) {
            if (! $amount instanceof Money) {
                // Read with `??`: the SDK declares both without a default, so on an entry that carries neither
                // a plain read is an error of its own, raised from the line that reports the first.
                Log::warning('billing: Mollie reported a chargeback amount this package cannot read, so it was left out', [
                    'chargeback' => MollieValue::id($chargeback->id ?? null),
                    'amount' => $chargeback->amount ?? null,
                ]);

                continue;
            }

            yield new ChargebackReceived(
                $customerReference,
                $paymentReference,
                $amount,
                disputeReference: MollieValue::id($chargeback->id),
            );
        }
    }

    /**
     * What the chargebacks still standing took back, in the payment's currency.
     *
     * An entry in another currency cannot be added to the rest, and is left out rather than converted. One
     * whose amount cannot be read is left out as well, and without a warning of its own: {@see self::from()}
     * names it, and the mapper reads both off one list, so a second line would report one entry twice.
     *
     * @param  iterable<mixed>  $chargebacks
     */
    public static function standing(iterable $chargebacks, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach (self::standingWithAmounts($chargebacks) as [, $amount]) {
            if ($amount instanceof Money && $amount->currency === $currency) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    /**
     * Every chargeback of the payment as an account's event reports it, a reversed one included, in Mollie's order.
     *
     * An entry whose amount cannot be read is left out and named in a warning, the way {@see self::from()} names one:
     * an account's event reports it nowhere else.
     *
     * @param  iterable<mixed>  $chargebacks
     * @return list<PaymentChargeback>
     */
    public static function records(iterable $chargebacks): array
    {
        $records = [];

        foreach ($chargebacks as $chargeback) {
            if (! $chargeback instanceof Chargeback) {
                continue;
            }

            try {
                $amount = MollieAmount::fromResource($chargeback->amount);
            } catch (Throwable) {
                Log::warning('billing: Mollie reported a chargeback amount this package cannot read, so it was left out', [
                    'chargeback' => MollieValue::id($chargeback->id ?? null),
                    'amount' => $chargeback->amount ?? null,
                ]);

                continue;
            }

            $reason = $chargeback->reason;

            $records[] = new PaymentChargeback(
                MollieValue::id($chargeback->id),
                $amount,
                is_string($chargeback->reversedAt) && $chargeback->reversedAt !== '' ? ChargebackOutcome::Reversed : ChargebackOutcome::Open,
                self::time($chargeback->createdAt),
                $reason instanceof stdClass ? self::text($reason->code ?? null) : null,
                $reason instanceof stdClass ? self::text($reason->description ?? null) : null,
            );
        }

        return $records;
    }

    /**
     * The ids of the chargebacks that still stand.
     *
     * @param  iterable<mixed>  $chargebacks
     * @return list<string>
     */
    public static function standingIds(iterable $chargebacks): array
    {
        $ids = [];

        foreach (self::standingWithAmounts($chargebacks) as [$chargeback]) {
            $id = MollieValue::id($chargeback->id);

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Each chargeback that still stands, with the amount read off it, or null where it cannot be read.
     *
     * @param  iterable<mixed>  $chargebacks
     * @return iterable<array{0: Chargeback, 1: ?Money}>
     */
    private static function standingWithAmounts(iterable $chargebacks): iterable
    {
        foreach ($chargebacks as $chargeback) {
            if (! $chargeback instanceof Chargeback) {
                continue;
            }

            if (is_string($chargeback->reversedAt) && $chargeback->reversedAt !== '') {
                continue;
            }

            try {
                $amount = MollieAmount::fromResource($chargeback->amount);
            } catch (Throwable) {
                $amount = null;
            }

            yield [$chargeback, $amount];
        }
    }

    /** A time Mollie wrote in ISO-8601, or null where it wrote none or one that does not parse. */
    private static function time(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** A non-empty string Mollie wrote, or null. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
