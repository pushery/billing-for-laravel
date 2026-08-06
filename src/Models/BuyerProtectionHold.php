<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\BuyerProtectionState;

/**
 * One sale whose payout is waiting.
 *
 * @property int $id
 * @property string $charge_reference
 * @property ?string $merchant_type
 * @property ?string $merchant_id
 * @property string $currency
 * @property int $charge_minor
 * @property int $platform_fee_minor
 * @property int $seller_net_minor
 * @property int $buyer_refund_minor
 * @property BuyerProtectionState $state
 * @property Carbon $confirm_by
 * @property Carbon $decide_by
 * @property ?Carbon $settled_at
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class BuyerProtectionHold extends Model
{
    protected $table = 'billing_buyer_protection_holds';

    /** @var list<string> */
    protected $fillable = [
        'charge_reference', 'merchant_type', 'merchant_id', 'currency', 'charge_minor',
        'platform_fee_minor', 'seller_net_minor', 'buyer_refund_minor', 'state',
        'confirm_by', 'decide_by', 'settled_at', 'merchant_erased_at',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one read back.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'platform_fee_minor' => 0,
        'seller_net_minor' => 0,
        'buyer_refund_minor' => 0,
    ];

    /** @var array<string,string> */
    protected $casts = [
        'charge_minor' => 'integer',
        'platform_fee_minor' => 'integer',
        'seller_net_minor' => 'integer',
        'buyer_refund_minor' => 'integer',
        'state' => BuyerProtectionState::class,
        'confirm_by' => UtcDateTime::class,
        'decide_by' => UtcDateTime::class,
        'settled_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];
}
