<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Enums\TaxIdVerificationStatus;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * One answer the provider reported about a buyer's tax ID.
 *
 * Appended and never edited: each status is a fact about the moment it was reported, and that moment is what matters
 * when a reverse-charged sale turns out to have gone to a number the register does not know.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string $provider
 * @property string $customer_reference
 * @property string $tax_id_reference
 * @property string $type
 * @property string $value
 * @property TaxIdVerificationStatus $status
 * @property string|null $verified_name
 * @property string|null $verified_address
 * @property Carbon $reported_at
 * @property Carbon|null $owner_erased_at
 */
final class TaxIdVerification extends Model
{
    use AppendOnly;

    protected $table = 'billing_tax_id_verifications';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'customer_reference', 'tax_id_reference', 'type', 'value', 'status',
        'verified_name', 'verified_address', 'reported_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'status' => TaxIdVerificationStatus::class,
        'reported_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /**
     * An erasure unlinks the person and leaves the answer as it was reported.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['owner_type', 'owner_id', 'owner_erased_at', 'updated_at'];
    }

    /** Never, by any path: the erasure axis holds this table as RETAINED. */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A tax ID answer records what the provider reported at one moment and is never rewritten; attempted '
            .'to change '.implode(', ', $columns).'. A later answer is a row of its own.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A tax ID answer supports invoices that are kept for years, so it is unlinked from an erased owner '
            .'and never deleted.';
    }
}
