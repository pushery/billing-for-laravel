<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pushery\Billing\ValueObjects\Money;

/**
 * One provider-neutral line of a local subscription — what is billed this cycle, and for how much.
 *
 * A line is either fixed (an amount known when it is written) or metered (`metered`, amount unknown
 * until the cycle is priced by the resolver named in `preprocessor`). Reading `amount_minor` on a
 * metered line before it is priced yields null, which is a real state and not a missing value.
 *
 * @property int $billing_subscription_id
 * @property string $plan_key
 * @property ?string $price_ref
 * @property ?int $quantity
 * @property bool $metered
 * @property ?int $amount_minor
 * @property string $currency
 * @property ?string $preprocessor
 */
final class SubscriptionItem extends Model
{
    protected $table = 'billing_subscription_items';

    /** @var list<string> */
    protected $fillable = [
        'billing_subscription_id', 'plan_key', 'price_ref', 'quantity', 'metered',
        'amount_minor', 'currency', 'preprocessor',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'quantity' => 'integer',
        'metered' => 'boolean',
        'amount_minor' => 'integer',
    ];

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'billing_subscription_id');
    }

    /**
     * This line's amount for the cycle, or null while a metered line is still unpriced.
     *
     * Returning null rather than a zero Money is deliberate: zero is a legitimate amount (a metered
     * line with no usage), so collapsing "not yet priced" into it would make an unpriced cycle look
     * settled and bill the customer nothing without anything being wrong.
     */
    public function amount(): ?Money
    {
        return $this->amount_minor === null ? null : Money::of($this->amount_minor, $this->currency);
    }
}
