<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Consumer\CustomerDeclarations;
use Pushery\Billing\Enums\CustomerAudience;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Enums\VatIdValidation;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\Models\Concerns\StoresMomentsWithTheirOffset;

/**
 * One thing a customer declared about themselves: whether they buy as a consumer or as a business, in the wording they
 * confirmed and at the moment they confirmed it.
 *
 * Written once and never changed, so a later declaration is a second row beside the first. The latest is the one in
 * force, and {@see CustomerDeclarations} reads it.
 *
 * @property int $id
 * @property ?string $owner_type
 * @property int|string|null $owner_id
 * @property CustomerAudience $audience
 * @property CustomerType $customer_type
 * @property string $statement
 * @property ?string $statement_version
 * @property ?string $business_name
 * @property ?string $business_address
 * @property ?string $vat_id
 * @property ?VatIdValidation $vat_id_validation
 * @property ?Carbon $vat_id_checked_at
 * @property ?bool $renewal_terms_consented
 * @property ?string $renewal_terms_statement
 * @property ?string $reference
 * @property Carbon $declared_at
 * @property ?Carbon $owner_erased_at
 */
class CustomerDeclaration extends Model
{
    use Replaceable;
    use StoresMomentsWithTheirOffset;

    protected $table = 'billing_customer_declarations';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'audience', 'customer_type', 'statement', 'statement_version',
        'business_name', 'business_address', 'vat_id', 'vat_id_validation', 'vat_id_checked_at',
        'renewal_terms_consented', 'renewal_terms_statement', 'reference', 'declared_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'audience' => CustomerAudience::class,
        'customer_type' => CustomerType::class,
        'vat_id_validation' => VatIdValidation::class,
        'vat_id_checked_at' => UtcDateTime::class,
        'renewal_terms_consented' => 'boolean',
        'declared_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];
}
