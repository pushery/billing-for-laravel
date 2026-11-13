<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\Models\Concerns\StoresMomentsWithTheirOffset;

/**
 * A fixed discount the application granted an owner, which the owner's next cycles use up before tax.
 *
 * What is left of it is never stored: it is the granted amount less the uses of orders that did not fail
 * ({@see DiscountAllowanceUse}), so it cannot drift from the cycles that consumed it.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $currency
 * @property int $amount_minor
 * @property string $cause
 * @property string $description
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class DiscountAllowance extends Model
{
    use Replaceable;
    use StoresMomentsWithTheirOffset;

    protected $table = 'billing_discount_allowances';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'currency', 'amount_minor', 'cause', 'description'];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
    ];
}
