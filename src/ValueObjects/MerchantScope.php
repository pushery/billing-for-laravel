<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which merchant a piece of subscription state belongs to — or the platform itself, when there is no
 * marketplace.
 *
 * This is the LOCAL identity of a merchant, not its provider account. A subscription row is keyed by it so
 * one billable can hold many concurrent subscriptions, one per creator; the money path's own
 * MerchantAccountReference answers a different question (which connected account the provider pays) and is
 * resolved separately. Keeping the two apart is deliberate: the local key must be stable across a provider
 * re-onboarding, and the provider reference must be free to change without moving a subscription.
 *
 * It renders to a single sentinel string, `uid()`, that every database engine compares identically — the
 * reason the subscription table keys on a NOT-NULL string rather than a nullable morph, which would let the
 * single-seller uniqueness silently disappear on an engine whose NULLs do not collide. The platform's uid is
 * the literal `platform`; a real merchant's is `m:<type>#<id>`, and the `m:` prefix is what makes the two
 * structurally unable to collide.
 */
final readonly class MerchantScope
{
    public function __construct(
        public ?string $type = null,
        public int|string|null $id = null,
    ) {}

    /** The platform itself — the single-seller case, and the default a null merchant collapses to. */
    public static function platform(): self
    {
        return new self;
    }

    /** The scope of a host merchant model, taken from its morph identity. */
    public static function forMerchant(Model $merchant): self
    {
        $id = $merchant->getKey();

        // A merchant with no usable key cannot be scoped — that is an unsaved model, not the platform, so it
        // fails closed rather than silently collapsing to the platform sentinel and mis-keying a row.
        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException('A merchant must be a saved model with an integer or string key to be scoped.');
        }

        return new self($merchant->getMorphClass(), $id);
    }

    /** Whether this is the platform rather than a merchant. */
    public function isPlatform(): bool
    {
        return $this->type === null || $this->id === null;
    }

    /**
     * The sentinel string the subscription row is keyed by.
     *
     * `platform` for the platform; `m:<type>#<id>` for a merchant. The prefix is not decoration — it is what
     * guarantees a merchant whose own key happened to be the string `platform` cannot collide with the
     * platform sentinel, so the single-seller invariant holds even against an adversarial key.
     */
    public function uid(): string
    {
        if ($this->isPlatform()) {
            return 'platform';
        }

        return 'm:'.$this->type.'#'.$this->id;
    }
}
