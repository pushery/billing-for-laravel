<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A buyer was about to be sent to the provider for a purchase whose declarations are not on file.
 *
 * ## Why this refuses at the checkout and not at provision
 *
 * The gate before PROVISION already exists and already refuses. On its own it is the wrong end of the
 * problem: the buyer has paid by then. A work whose right of withdrawal ends at provision cannot be handed
 * over without the two declarations, so a purchase that reaches the provider without them buys something
 * the buyer cannot receive — and the operator is left issuing a refund for a sale the package could have
 * declined before a single euro moved.
 *
 * So the same rule is asked twice, deliberately, at both ends. This one costs nothing when it fires.
 *
 * ## Why an unclassified product refuses too
 *
 * The withdrawal gate needs TWO conditions to bite — an active consumer-rights profile AND a classified
 * archetype — and until now the second one failing meant the first was never consulted. An operator who
 * turned the profile on and left one work without an `archetype` key got that work sold and delivered with
 * no consent recorded, and nothing anywhere said so. `billing:doctor` reported the combination; nothing
 * refused it.
 *
 * With a profile active, an unclassified product is therefore a refusal rather than a pass. It is the only
 * safe reading: "nobody classified this" and "this needs no declarations" are indistinguishable to the
 * runtime, and one of them is a statutory failure. Nothing changes for an install with no profile set,
 * where the classification is not read at all.
 */
final class WithdrawalDeclarationsMissing extends RuntimeException
{
    public function __construct(
        public readonly string $addonKey,
        public readonly string $why,
    ) {
        parent::__construct("Add-on '{$addonKey}' cannot go to checkout: {$why}");
    }

    /** The buyer made neither declaration, or only one of the two. */
    public static function incomplete(string $addonKey): self
    {
        return new self(
            $addonKey,
            'a consumer-rights profile is active and this product needs the buyer to (a) ask for provision '
            .'to begin before the withdrawal period runs out and (b) acknowledge that it ends their right to '
            .'withdraw. Record both with WithdrawalConsentLedger before starting the checkout; neither alone '
            .'is enough, and a single combined checkbox is not two declarations.',
        );
    }

    /** Nothing classifies the product, so the runtime cannot tell which declarations it needs. */
    public static function unclassified(string $addonKey): self
    {
        return new self(
            $addonKey,
            'a consumer-rights profile is active and nothing classifies this product, so the runtime cannot '
            .'tell whether it needs declarations. Set `billing.addons.'.$addonKey.'.archetype`. Unclassified '
            .'is not the same as "no right applies" — treating it that way is how a work gets sold and '
            .'delivered with no consent on file.',
        );
    }
}
