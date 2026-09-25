<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\InPersonSaleStatus;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\ValueObjects\Money;

/**
 * One sale put on a card reader, with the tax decided for it at the counter.
 *
 * @property int $id
 * @property string $provider
 * @property string $payment_reference
 * @property string $reader
 * @property string $sold_at
 * @property string $description
 * @property TaxArchetype $tax_archetype
 * @property ?TaxArchetype $sold_alongside_archetype
 * @property string $currency
 * @property int $gross_minor
 * @property int $tax_minor
 * @property int $tax_rate_bps
 * @property TaxRateCategory $tax_rate_category
 * @property PlaceOfSupplyRule $place_of_supply_rule
 * @property ?TaxExemptionReason $tax_exemption_reason
 * @property ?string $reference
 * @property InPersonSaleStatus $status
 * @property ?Carbon $paid_at
 * @property ?int $invoice_id
 */
class InPersonSaleRecord extends Model
{
    use Replaceable;

    /**
     * The name a receipt from the counter is filed under as its owner.
     *
     * An alias rather than the class name, registered in the morph map by the service provider, so a host that
     * enforces a morph map accepts it and a host that replaces this model still resolves to its own class.
     */
    public const string MORPH_ALIAS = 'billing_in_person_sale';

    protected $table = 'billing_in_person_sales';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'payment_reference', 'reader', 'sold_at', 'description', 'tax_archetype', 'sold_alongside_archetype',
        'currency', 'gross_minor', 'tax_minor', 'tax_rate_bps', 'tax_rate_category', 'place_of_supply_rule',
        'tax_exemption_reason', 'reference', 'status', 'paid_at', 'invoice_id',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'tax_archetype' => TaxArchetype::class,
        'sold_alongside_archetype' => TaxArchetype::class,
        'gross_minor' => 'integer',
        'tax_minor' => 'integer',
        'tax_rate_bps' => 'integer',
        'tax_rate_category' => TaxRateCategory::class,
        'place_of_supply_rule' => PlaceOfSupplyRule::class,
        'tax_exemption_reason' => TaxExemptionReason::class,
        'status' => InPersonSaleStatus::class,
        'paid_at' => UtcDateTime::class,
        'invoice_id' => 'integer',
    ];

    /** What the buyer pays at the reader, tax included. */
    public function gross(): Money
    {
        return new Money($this->gross_minor, $this->currency);
    }

    /** The tax contained in the gross, as it was decided when the sale went onto the reader. */
    public function tax(): Money
    {
        return new Money($this->tax_minor, $this->currency);
    }
}
