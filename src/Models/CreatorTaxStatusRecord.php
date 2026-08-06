<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;

/**
 * One interval of a creator's tax standing.
 *
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property ?Carbon $merchant_erased_at
 * @property CreatorTaxStatus $status
 * @property Carbon $effective_from
 * @property ?Carbon $effective_to
 * @property CreatorTaxStatusSource $source
 * @property ?string $evidence_ref
 * @property ?int $business_founded_year
 * @property ?Carbon $attested_until
 * @property ?Carbon $hold_announced_at
 * @property ?Carbon $created_at
 */
final class CreatorTaxStatusRecord extends Model
{
    protected $table = 'billing_creator_tax_statuses';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'merchant_erased_at', 'status', 'effective_from', 'effective_to',
        'source', 'evidence_ref', 'business_founded_year', 'attested_until', 'hold_announced_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => CreatorTaxStatus::class,
        'business_founded_year' => 'integer',
        'source' => CreatorTaxStatusSource::class,
        // Every instant here is compared against a supply date, so all of them are kept in UTC on read and
        // write. The default cast reads back in app.timezone, which would move an interval boundary by the
        // offset and answer a document with the wrong side of a midnight status change.
        'effective_from' => UtcDateTime::class,
        'effective_to' => UtcDateTime::class,
        'attested_until' => UtcDateTime::class,
        'hold_announced_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /** Whether this interval covers a moment. Half-open: the start is included, the end is not. */
    public function covers(Carbon $moment): bool
    {
        if ($this->effective_from->greaterThan($moment)) {
            return false;
        }

        return ! $this->effective_to instanceof Carbon || $this->effective_to->greaterThan($moment);
    }

    /**
     * Whether the attestation behind this interval had run out by a moment.
     *
     * An expired declaration is not a weaker standing, it is no standing: what it asserted was true of a
     * period that has ended, and the question it answered has to be asked again. Treating it as still
     * current is exactly the silent default the whole area exists to prevent — with the added twist that it
     * would look like a recorded answer rather than an assumption.
     */
    public function hasExpiredBy(Carbon $moment): bool
    {
        return $this->attested_until instanceof Carbon && $this->attested_until->lessThanOrEqualTo($moment);
    }
}
