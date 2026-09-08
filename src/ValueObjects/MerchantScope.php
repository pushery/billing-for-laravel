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

    /**
     * The scope a stored `merchant_uid` names.
     *
     * The rendering is lossless by construction — the type and the key are both in the string — but until
     * this existed nothing read them back, so anything holding only the sentinel could scope a query and
     * could not say WHOSE relationship it had. That is the shape a sweep needs: it finds rows first and has
     * to name the merchant afterwards.
     *
     * Splits on the FIRST `#` only. A merchant type is a class name or a morph alias and contains no `#`;
     * a KEY may well contain one, and splitting on the last would move part of it into the type.
     *
     * Anything that is not a well-formed merchant sentinel reads as the platform. That is the same
     * direction `isPlatform()` already takes for a half-built scope, and it is the safe one here: an
     * unparseable value becoming "the platform" narrows a query to the single-seller row, while inventing a
     * merchant out of it would point the query at somebody.
     */
    public static function fromUid(string $uid): self
    {
        if (! str_starts_with($uid, 'm:')) {
            return self::platform();
        }

        $rest = substr($uid, 2);
        $separator = strpos($rest, '#');

        if ($separator === false || $separator === 0) {
            return self::platform();
        }

        $id = substr($rest, $separator + 1);

        return $id === '' ? self::platform() : new self(substr($rest, 0, $separator), $id);
    }
}
