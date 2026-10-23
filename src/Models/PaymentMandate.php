<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * One stored mandate a local-engine driver may charge off-session.
 *
 * The row exists so the engine never has to ask a provider on the hot path. `billing:run` collects due
 * cycles unattended; a call that hangs there stalls every remaining subscription behind it.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $provider
 * @property string $mandate_reference
 * @property string $method
 * @property string $status
 * @property bool $is_default
 * @property bool $is_fallback the one mandate a cycle is charged to once when the default could not pay
 * @property ?string $customer_reference
 * @property ?string $card_brand
 * @property ?string $card_last4
 * @property ?int $card_exp_month
 * @property ?int $card_exp_year
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PaymentMandate extends Model
{
    use Replaceable;

    /**
     * The one status a mandate may be charged under. Stated as what is chargeable rather than what is not,
     * so a status a provider introduces tomorrow is refused until somebody decides it may be charged —
     * the safe direction for a check that stands in front of a debit.
     */
    public const string CHARGEABLE = 'valid';

    protected $table = 'billing_payment_mandates';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'mandate_reference',
        'method', 'status', 'is_default', 'is_fallback', 'customer_reference',
        'card_brand', 'card_last4', 'card_exp_month', 'card_exp_year',
    ];

    /**
     * The same defaults the schema carries, so a freshly built row reads like one read back.
     *
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'status' => self::CHARGEABLE,
        'is_default' => false,
        'is_fallback' => false,
    ];

    /** @var array<string,string> */
    protected $casts = ['is_default' => 'boolean', 'is_fallback' => 'boolean', 'card_exp_month' => 'integer', 'card_exp_year' => 'integer'];

    /**
     * The chargeable default for an owner at one provider, or null when they currently have no way to pay.
     *
     * A revoked mandate is never returned as a fallback. Handing one back would send the engine into a
     * charge it cannot win and leave the subscriber in dunning over a method they had already withdrawn —
     * where the honest answer is that this owner has no usable mandate right now.
     */
    public static function defaultFor(string $ownerType, int|string $ownerId, string $provider): ?self
    {
        return static::model()::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('provider', $provider)
            ->where('status', self::CHARGEABLE)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The chargeable fallback an owner keeps at one provider, or null when they keep none.
     *
     * Never the default, even where a row says both: the default is what was just refused, and charging it again
     * as its own fallback would be a second attempt on the same method minutes after the first.
     */
    public static function fallbackFor(string $ownerType, int|string $ownerId, string $provider): ?self
    {
        return static::model()::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('provider', $provider)
            ->where('status', self::CHARGEABLE)
            ->where('is_fallback', true)
            ->where('is_default', false)
            ->orderByDesc('id')
            ->first();
    }

    /** The reference the rails charge against, read from the row rather than fetched. */
    public function toReference(): MandateReference
    {
        return new MandateReference(
            $this->mandate_reference,
            $this->method,
            $this->status === self::CHARGEABLE,
            $this->customer_reference,
        );
    }

    /**
     * Make this the mandate the engine charges, demoting the previous one.
     *
     * Scoped to the owner and the provider: an install running two drivers has a default per driver, and
     * promoting one must not quietly unset the other. Both writes share a transaction, because the state
     * in between is two defaults — which is not a tie but a coin toss on the next charge, and the two may
     * be different cards.
     */
    public function makeDefault(): void
    {
        $this->getConnection()->transaction(function (): void {
            static::model()::query()
                ->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id)
                ->where('provider', $this->provider)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false, 'updated_at' => Carbon::now()]);

            // A default is never its own fallback, so a fallback promoted to default stops being one.
            $this->update(['is_default' => true, 'is_fallback' => false]);
        });
    }

    /**
     * Make this the mandate a cycle is charged to once when the default could not pay, in place of any other.
     *
     * Scoped to the owner and the provider, as the default is, and refused for the default itself: the fallback
     * exists for the moment the default has just failed.
     *
     * @throws LogicException when this mandate is the default
     */
    public function makeFallback(): void
    {
        if ($this->is_default) {
            throw new LogicException('The default payment method cannot be its own fallback. Choose another method as the fallback, or make another the default first.');
        }

        $this->getConnection()->transaction(function (): void {
            static::model()::query()
                ->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id)
                ->where('provider', $this->provider)
                ->whereKeyNot($this->getKey())
                ->update(['is_fallback' => false, 'updated_at' => Carbon::now()]);

            $this->update(['is_fallback' => true]);
        });
    }

    /** The method as the account hub renders it, read from the row. */
    public function toPaymentMethod(): PaymentMethod
    {
        return new PaymentMethod(
            id: (string) $this->mandate_reference,
            type: (string) ($this->method ?? 'mandate'),
            isDefault: (bool) $this->is_default,
            brand: $this->card_brand,
            last4: $this->card_last4,
            expMonth: $this->card_exp_month,
            expYear: $this->card_exp_year,
            isFallback: (bool) $this->is_fallback,
        );
    }

    /** @return MorphTo<Model,$this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
