<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A buyer's customer reference inside one merchant account.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $provider
 * @property string $account_reference
 * @property string $customer_reference
 */
final class MerchantCustomer extends Model
{
    protected $table = 'billing_merchant_customers';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'provider', 'account_reference', 'customer_reference'];

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
