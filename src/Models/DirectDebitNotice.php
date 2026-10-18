<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * A notice the local engine sent before it drew a SEPA direct debit: the amount, the mandate and the day.
 *
 * A notice for a subscription is `recurring`: it announces the amount for its first debit and for each later due
 * date of that subscription, so a subscription whose amount stays the same is announced once. A notice that names
 * an order covers that charge alone.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $provider
 * @property string $mandate_reference
 * @property ?int $subscription_id
 * @property ?int $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $debit_on
 * @property bool $recurring
 * @property Carbon $announced_at
 */
class DirectDebitNotice extends Model
{
    use Replaceable;

    public $timestamps = false;

    protected $table = 'billing_direct_debit_notices';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'mandate_reference', 'subscription_id', 'order_id',
        'amount_minor', 'currency', 'debit_on', 'recurring', 'announced_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'debit_on' => 'date',
        'recurring' => 'boolean',
        'announced_at' => UtcDateTime::class,
    ];
}
