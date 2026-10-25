<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\OwnerTimezone;

/**
 * A moment in the zone an owner reads dates in, for a notice that names a day.
 *
 * Without an answer from OwnerTimezone the moment is passed on as it is. An answer that is no time zone identifier
 * is reported and the moment passed on as it is: the notice then names the stored day rather than failing inside the
 * run that sends it, which a webhook effect would retry for good.
 */
final readonly class OwnersZone
{
    public static function of(Model $owner, DateTimeInterface $moment): DateTimeInterface
    {
        $container = Container::getInstance();
        $zone = $container->make(OwnerTimezone::class)->for($owner);

        if ($zone === null) {
            return $moment;
        }

        if (! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $container->make(ExceptionHandler::class)->report(new InvalidArgumentException(sprintf(
                'OwnerTimezone answered [%s] for an owner of type [%s], which is no time zone identifier. The notice names the day as stored.',
                $zone,
                $owner->getMorphClass(),
            )));

            return $moment;
        }

        return CarbonImmutable::instance($moment)->setTimezone($zone);
    }
}
