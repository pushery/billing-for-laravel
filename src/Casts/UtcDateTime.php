<?php

declare(strict_types=1);

namespace Pushery\Billing\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A datetime cast that keeps the value in UTC regardless of the app timezone.
 *
 * Provider timestamps (a subscription's current-period boundaries, trial and grace ends) are absolute
 * instants stored in UTC. The framework's default `datetime` cast reads a column back in `app.timezone`,
 * so on a non-UTC app (a German-market billing package is exactly that) the stored UTC wall-clock string
 * is reinterpreted in the local zone and the instant shifts by the offset — enough to bucket a usage event
 * into the wrong billing cycle at a period boundary, or to expire a trial an hour early. This cast writes
 * and reads the value as UTC on both sides, so the instant round-trips exactly whatever the app timezone.
 *
 * @implements CastsAttributes<Carbon, Carbon|DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value, 'UTC');
        }

        // Interpret the stored wall-clock string as UTC — the zone it was written in — never the app zone.
        return is_string($value) ? Carbon::parse($value, 'UTC') : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Convert whatever is assigned (a UTC Carbon, a zoned datetime, a string) to UTC before storing, so
        // the column always holds a UTC wall-clock that get() can read back without a zone shift.
        $datetime = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

        return $datetime->utc()->format('Y-m-d H:i:s');
    }
}
