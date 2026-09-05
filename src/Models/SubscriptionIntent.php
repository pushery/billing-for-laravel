<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * A subscription somebody asked for, waiting for the mandate that makes it possible.
 *
 * It exists only between the redirect and the webhook. A customer who completes the payment turns theirs
 * into a subscription; a customer who closes the tab leaves it unclaimed, and an unclaimed intent is
 * exactly as harmless as it sounds — no access, no charge, nothing anyone sees.
 *
 * There is deliberately no `owner()` relation. The morph columns are here so an erasure can find the row
 * and so an operator can read who asked; nothing in the package resolves them to a model, because the one
 * thing that acts on this row — the mandate webhook — copies them straight onto the subscription. A
 * relation nobody calls is a method the next reader has to decide about, and this one would only ever be
 * decided the way it is written here.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $provider
 * @property string $tier_key
 * @property ?string $coupon_code
 * @property string $payment_reference
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $claimed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class SubscriptionIntent extends Model
{
    protected $table = 'billing_subscription_intents';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'tier_key', 'coupon_code', 'payment_reference', 'trial_ends_at',
        'claimed_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'trial_ends_at' => UtcDateTime::class,
        'claimed_at' => UtcDateTime::class,
    ];

    /** Whether a mandate has already turned this into a subscription. */
    public function isClaimed(): bool
    {
        return $this->claimed_at instanceof Carbon;
    }
}
