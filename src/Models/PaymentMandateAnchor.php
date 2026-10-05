<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * The row an owner's mandate storing locks per provider, so that two of them take turns, the first one included.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $provider
 */
class PaymentMandateAnchor extends Model
{
    use Replaceable;

    public $timestamps = false;

    protected $table = 'billing_payment_mandate_anchors';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'provider'];
}
