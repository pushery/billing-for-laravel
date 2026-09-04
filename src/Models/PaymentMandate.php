<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\ValueObjects\MandateReference;

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
 * @property ?string $customer_reference
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class PaymentMandate extends Model
{
    /**
     * The one status a mandate may be charged under. Stated as what IS chargeable rather than what is not,
     * so a status a provider introduces tomorrow is refused until somebody decides it may be charged —
     * the safe direction for a check that stands in front of a debit.
     */
    public const string CHARGEABLE = 'valid';

    protected $table = 'billing_payment_mandates';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'mandate_reference',
        'method', 'status', 'is_default', 'customer_reference',
    ];

    /**
     * The same defaults the schema carries, so a freshly built row reads like one read back.
     *
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'status' => self::CHARGEABLE,
        'is_default' => false,
    ];

    /** @var array<string,string> */
    protected $casts = ['is_default' => 'boolean'];

    /**
     * The chargeable default for an owner at one provider, or null when they currently have no way to pay.
     *
     * A revoked mandate is never returned as a fallback. Handing one back would send the engine into a
     * charge it cannot win and leave the subscriber in dunning over a method they had already withdrawn —
     * where the honest answer is that this owner has no usable mandate right now.
     */
    public static function defaultFor(string $ownerType, int|string $ownerId, string $provider): ?self
    {
        return self::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('provider', $provider)
            ->where('status', self::CHARGEABLE)
            ->orderByDesc('is_default')
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
     * Scoped to the owner AND the provider: an install running two drivers has a default per driver, and
     * promoting one must not quietly unset the other. Both writes share a transaction, because the state
     * in between is two defaults — which is not a tie but a coin toss on the next charge, and the two may
     * be different cards.
     */
    public function makeDefault(): void
    {
        $this->getConnection()->transaction(function (): void {
            self::query()
                ->where('owner_type', $this->owner_type)
                ->where('owner_id', $this->owner_id)
                ->where('provider', $this->provider)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false, 'updated_at' => Carbon::now()]);

            $this->update(['is_default' => true]);
        });
    }

    /** @return MorphTo<Model,$this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
