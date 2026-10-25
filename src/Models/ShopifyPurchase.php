<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * A one-time purchase the app opened at Shopify.
 *
 * Shopify's purchase carries nothing of the app's but its name, so this row is how the webhook that reports it
 * finds the owner, the add-on, the amount and the declarations again. It holds no money and no document: Shopify
 * charges the merchant, and the purchase is recorded as an add-on purchase once Shopify reports it active.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $purchase_id
 * @property string $addon_key
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $declaration_reference
 * @property ?string $caller_reference
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ShopifyPurchase extends Model
{
    use Replaceable;

    protected $table = 'billing_shopify_purchases';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'purchase_id', 'addon_key', 'amount_minor', 'currency', 'declaration_reference',
        'caller_reference',
    ];

    /** @var array<string, string> */
    protected $casts = ['amount_minor' => 'integer'];
}
