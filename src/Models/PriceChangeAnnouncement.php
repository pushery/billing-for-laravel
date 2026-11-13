<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\Models\Concerns\StoresMomentsWithTheirOffset;

/**
 * That a subscription was told of a declared price change, and from which day the new price holds for it.
 *
 * @property int $id
 * @property int $billing_subscription_id
 * @property string $price_change
 * @property Carbon $announced_at
 * @property Carbon $effective_on
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PriceChangeAnnouncement extends Model
{
    use Replaceable;
    use StoresMomentsWithTheirOffset;

    protected $table = 'billing_price_change_announcements';

    /** @var list<string> */
    protected $fillable = ['billing_subscription_id', 'price_change', 'announced_at', 'effective_on'];

    /** @var array<string,string> */
    protected $casts = [
        'billing_subscription_id' => 'integer',
        'announced_at' => UtcDateTime::class,
        'effective_on' => 'date',
    ];
}
