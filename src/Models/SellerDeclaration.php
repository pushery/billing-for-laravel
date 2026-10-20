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
 * What a seller outside the Union declared to the platform, as it was declared.
 *
 * The platform treated as supplying the goods relies on these facts to classify a sale (Art. 14a VAT Directive), and it
 * has to be able to show what it relied on and when (Art. 5c Implementing Regulation 282/2011). So the row keeps the
 * statement the seller confirmed, a SHA-256 hash of it, the facts it states and the moment it arrived, and it is written
 * once. A seller who declares again writes a new row, and the newest one is the declaration in force.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property int|string|null $merchant_id
 * @property string $established_in
 * @property list<string> $tax_numbers
 * @property string $goods
 * @property ?string $dispatched_from
 * @property string $statement
 * @property string $statement_hash
 * @property string $locale
 * @property Carbon $declared_at
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SellerDeclaration extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_seller_declarations';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'established_in', 'tax_numbers', 'goods', 'dispatched_from',
        'statement', 'statement_hash', 'locale', 'declared_at', 'merchant_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'tax_numbers' => 'array',
        'declared_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /**
     * What the seller declared stays as it arrived; only an erasure unlinks it from the seller.
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
        return 'A seller declaration keeps what the seller declared to the platform, and that cannot be updated: '
            .implode(', ', $columns).'. A seller who declares again writes a new declaration.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A seller declaration is the evidence of what the platform relied on when it supplied the seller\'s goods, and a '
            .'caller does not delete it. An erasure unlinks it from the seller, and retention removes it ten years after that.';
    }
}
