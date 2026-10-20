<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Models\Concerns\AppendOnly;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * A signal that contradicts what a seller declared, with the moment the platform observed it.
 *
 * From that moment the platform knows better than the declaration, so it can no longer rely on it (Art. 5c
 * Implementing Regulation 282/2011): the seller's next sale waits until the seller has declared again after it. The
 * row records when the platform learned it, and it is written once.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property int|string|null $merchant_id
 * @property string $signal
 * @property ?string $detail
 * @property Carbon $observed_at
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SellerDeclarationContradiction extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_seller_declaration_contradictions';

    /** @var list<string> */
    protected $fillable = ['merchant_type', 'merchant_id', 'signal', 'detail', 'observed_at', 'merchant_erased_at'];

    /** @var array<string,string> */
    protected $casts = [
        'observed_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /**
     * What was observed stays as it was recorded; only an erasure unlinks it from the seller.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['merchant_type', 'merchant_id', 'merchant_erased_at', 'updated_at'];
    }

    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A contradiction of a seller declaration records what the platform observed and when, and that cannot be '
            .'updated: '.implode(', ', $columns).'.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A contradiction of a seller declaration is the record of when the platform learned better than the seller\'s '
            .'declaration, and a caller does not delete it. An erasure unlinks it, and retention removes it with the declarations.';
    }
}
