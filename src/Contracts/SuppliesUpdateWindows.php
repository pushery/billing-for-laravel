<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterval;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * How long a windowed sale's update window runs — per work, and per merchant.
 *
 * ## Why this is a second interface and not two more methods on the first
 *
 * {@see UpdatePolicyCatalog} is published. Adding methods to it would break every consumer implementation on
 * the next release, for a capability that is purely additive — the expensive way to ship something optional.
 * A consumer who never implements this keeps working, and their windowed grants behave exactly as they did.
 *
 * ## Why the length comes from the consumer at all
 *
 * For the same reason the policy does. "Updates for twelve months" is a term of sale a creator sets, and it
 * lives in the consumer's product data — this package has no place to hold it and no business guessing one.
 * A configured global default would be worse than nothing: it would put a term on every sale that no
 * creator agreed to.
 *
 * ## What was wrong before it existed
 *
 * Nothing wrote `update_window_ends_at`, so every windowed grant carried a null window, and the resolver
 * reads a windowed row with no window as broken and bounds it at the moment of purchase. That is exactly
 * what `Frozen` does — two of the four documented values of a shipped setting were byte-identical through
 * the only write path, and an operator selling "updates for a year" delivered frozen content.
 *
 * Null means "no preference at this level", exactly as on the policy catalog — not "no window". It is what
 * lets the next level answer, and if no level answers, the fail-closed reading stands.
 */
interface SuppliesUpdateWindows
{
    /** How long this work's update window runs, or null to fall through to the merchant's default. */
    public function windowForContent(ContentReference $content): ?CarbonInterval;

    /** The merchant's own default window, or null when they have expressed none. */
    public function windowForMerchant(?MerchantScope $merchant): ?CarbonInterval;
}
