<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A merchant's own ledger account, for the installation that books individual creditors.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property ?string $merchant_id
 * @property string $number
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class MerchantCreditorAccount extends Model
{
    protected $table = 'billing_merchant_creditor_accounts';

    /** @var list<string> */
    protected $fillable = ['merchant_type', 'merchant_id', 'number', 'merchant_erased_at'];

    /** @var array<string,string> */
    protected $casts = ['merchant_erased_at' => 'datetime'];
}
