<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A slim, project-neutral identity for a tier — what the shared account hub needs to label and
 * gate an owner without knowing the project's own enum or domain limits. The TierResolver returns
 * this; it absorbs the enum-vs-string split (one app's tier() returns an enum, another's a string)
 * so neither app's existing signature has to change.
 *
 * A TierIdentity NEVER implies access on its own: callers combine it with the subscription state.
 *
 * The `level` is a comparable rank — the tier's position in the catalog's configured order, low to high — so
 * a content gate can ask "at least this tier" without knowing the catalog. It defaults to 0 and is appended
 * last, so a positional construction written before it still compiles; the catalog fills it from the
 * ranking, which leaves single-tier reads unchanged.
 */
final readonly class TierIdentity
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $byok = false,
        public bool $untouchable = false,
        public int $level = 0,
    ) {}

    /** Whether this is the same tier key as another identity. */
    public function is(self|string $other): bool
    {
        return $this->key === ($other instanceof self ? $other->key : $other);
    }
}
