<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * What decided a sale's country, kept as country codes.
 *
 * @property int $id
 * @property ?string $owner_type
 * @property ?string $owner_id
 * @property string $reference
 * @property ?string $declared_country
 * @property ?string $payment_country
 * @property ?string $ip_country
 * @property string $resolved_country
 * @property ?string $resolved_subdivision
 * @property string $policy_version
 * @property int $required_signals
 * @property Carbon $resolved_at
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class PlaceEvidence extends Model
{
    use AppendOnly;

    protected $table = 'billing_place_evidence';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'reference', 'declared_country', 'payment_country', 'ip_country',
        'resolved_country', 'resolved_subdivision', 'policy_version', 'required_signals', 'resolved_at',
        'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'required_signals' => 'integer',
        'resolved_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /**
     * Erasure unlinks the person from a record whose CONTENT is untouched. Everything else would be
     * re-deciding after the fact what was already decided and acted on.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['owner_type', 'owner_id', 'owner_erased_at', 'updated_at'];
    }

    /** Never, by any path: an erasure axis holds this table as RETAINED — unlinked rather than removed. */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'Place evidence records what decided a sale at the moment it happened and cannot be '
            .'changed afterwards; attempted to change '.implode(', ', $columns).'. A later correction '
            .'works on the country originally resolved, never on a new one.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'This record is retained and unlinked when its subject is erased, never deleted — the fact '
            .'it holds stays true with nobody\'s name on it.';
    }
}
