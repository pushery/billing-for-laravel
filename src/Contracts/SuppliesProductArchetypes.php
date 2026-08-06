<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\TaxArchetype;

/**
 * A catalog that can say WHAT KIND of thing one of its entries is.
 *
 * ## Why this is its own contract and not a method on AddonCatalog
 *
 * `AddonCatalog` is implemented outside this package — a consumer with its own product store binds its
 * own. A method added there is a fatal error in code this package does not own and cannot fix. A sibling
 * contract costs nothing to ignore: a catalog either supplies archetypes or it does not, and the type
 * system answers that before anything runs. The same reasoning already governs {@see RoutesMoney} and
 * {@see MovesMerchantShare}.
 *
 * ## Why the archetype has to live on the PRODUCT
 *
 * The archetype decides tax regime, place of supply, rate band, reporting relevance and — the reason this
 * contract exists now — the kind of withdrawal right a buyer has. `RoutedPayment::charge()` already takes
 * one and refuses without it, so the routed sale is classified. But an add-on bought on the single-seller
 * path never passes through it, and nothing else carries a classification: the consent gate before
 * provision therefore had no type to act on and stayed silent, on every install, however configured.
 *
 * ## Null is an answer, and it is the honest one
 *
 * An unclassified entry returns null rather than a default. A guessed archetype is a guessed tax treatment
 * and a guessed withdrawal right, and both are wrong quietly.
 *
 * What a null MEANS is the caller's decision, and today exactly one caller makes it: the content-grant
 * effect reads it as "nothing to gate" — **regardless of the consumer-rights profile**, because the gate is
 * never reached on a null type. So classifying is what arms the withdrawal gate for a work, and a profile
 * alone does not. Implement this contract and leave a work unclassified and that work is provided without a
 * recorded consent; `billing:doctor` reports the combination.
 */
interface SuppliesProductArchetypes
{
    /** What kind of thing this entry is, or null when nobody has classified it. */
    public function archetypeFor(string $key): ?TaxArchetype;
}
