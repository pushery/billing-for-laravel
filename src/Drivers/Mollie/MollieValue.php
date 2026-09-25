<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use BackedEnum;
use Mollie\Api\Types\MandateMethod;
use ReflectionClass;

/**
 * Mollie's vocabulary as the strings both major versions of its SDK put on the wire.
 *
 * Version 3 of `mollie/mollie-api-php` names these values as class constants and hands a resource's status
 * back as a plain string. Version 4 turns each of them into a backed enum, renames the cases
 * (`PaymentStatus::OPEN` became `PaymentStatus::Open`) and hands a status back as that enum. The string
 * underneath is the same in both, and it is the only thing Mollie's API itself knows.
 *
 * So the driver writes the string, which every request of either version accepts, and reads whatever arrives
 * through {@see self::of()}. Comparing a version 4 status with a string directly is never an error, only never
 * equal: a check written that way stays green and quietly stops matching.
 *
 * The same split runs through the shapes the SDK hands back: `mixed` from `send()` and plain objects on
 * version 3, typed resources on version 4. The readers for those live here as well, so the driver asks one
 * class what it would otherwise have to ask two SDKs.
 */
final readonly class MollieValue
{
    public const string SEQUENCE_ONEOFF = 'oneoff';

    public const string SEQUENCE_FIRST = 'first';

    public const string SEQUENCE_RECURRING = 'recurring';

    public const string PAYMENT_OPEN = 'open';

    public const string PAYMENT_PENDING = 'pending';

    public const string PAYMENT_AUTHORIZED = 'authorized';

    public const string TERMINAL_ACTIVE = 'active';

    public const string METHOD_POINT_OF_SALE = 'pointofsale';

    /** The wire value of a field Mollie sent back: the string itself on SDK 3, the enum's value on SDK 4. */
    public static function of(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * A resource's id as a string.
     *
     * Version 4 types it as one. Version 3 leaves it as the JSON decoder made it, which is an integer for a
     * numeric id, and a cast written at the call site reads as dead code to every tool that analyses against
     * version 4.
     */
    public static function id(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * The resource a request handed back, if it is the one asked for, or null.
     *
     * Version 3 declares `send()` as returning `mixed`, version 4 as the resource. The check is the same either
     * way; taking `mixed` here keeps it meaningful for the version that needs it.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public static function narrow(mixed $sent, string $class): ?object
    {
        return $sent instanceof $class ? $sent : null;
    }

    /**
     * The code of a status reason, or null when it names none.
     *
     * Version 3 hands the reason back as a plain object that may carry no code, or one that is not text;
     * version 4 as a typed class whose code is always a string, if possibly an empty one. An empty code quotes
     * nothing either.
     */
    public static function reasonCode(mixed $reason): ?string
    {
        if (! is_object($reason) || ! isset($reason->code) || ! is_string($reason->code) || $reason->code === '') {
            return null;
        }

        return $reason->code;
    }

    /**
     * Every mandate method the installed SDK names.
     *
     * Read from the SDK rather than listed here, so a method Mollie adds reaches the driver with the next update.
     * An enum's cases are class constants as well, so one reflection answers for both majors.
     *
     * @return list<string>
     */
    public static function mandateMethods(): array
    {
        $methods = [];

        foreach (new ReflectionClass(MandateMethod::class)->getConstants() as $constant) {
            $value = self::of($constant);

            if ($value !== null) {
                $methods[] = $value;
            }
        }

        return $methods;
    }
}
