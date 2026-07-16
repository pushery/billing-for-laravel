<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's credit balance in one currency (minor units; may be spent down toward zero).
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $currency
 * @property int $balance_minor
 */
final class CreditBalance extends Model
{
    protected $table = 'billing_credit_balances';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'currency', 'balance_minor'];

    /** @var array<string,string> */
    protected $casts = ['balance_minor' => 'integer'];
}
