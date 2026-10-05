<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * The row a merchant's tax status recordings lock, so that two of them take turns, the first one included.
 *
 * @property int $id
 * @property string $merchant_type
 * @property int|string $merchant_id
 */
class CreatorTaxStatusAnchor extends Model
{
    use Replaceable;

    public $timestamps = false;

    protected $table = 'billing_creator_tax_status_anchors';

    /** @var list<string> */
    protected $fillable = ['merchant_type', 'merchant_id'];
}
