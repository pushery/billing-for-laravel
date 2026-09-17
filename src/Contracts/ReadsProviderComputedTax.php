<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ProviderComputedTax;

/**
 * Reads back the tax a provider computed for one hosted sale.
 *
 * ## The gap this closes, and why it looked unclosable
 *
 * Where the provider computes the tax, it does so from the address the buyer typed into the checkout,
 * and this package never sees a rate: the completion event carries amounts and nothing else. A buyer
 * on that lane therefore got no document at all, because issuing one would have meant stating a rate
 * nobody supplied — and a stated tax is owed by whoever stated it, whether or not it was ever due.
 *
 * The rate is not missing, though. It is on the sale's line items, one API call away, together with
 * the exact base and the exact tax. So the honest document was always available; what was missing was
 * a way for an effect to ask for it.
 *
 * ## It is a seam for the same reason the commission reader is
 *
 * No effect in `src/Webhooks/Effects/` reaches a provider directly, and that boundary is worth
 * keeping. The effect asks in the package's own vocabulary — "what tax did the provider apply to this
 * sale" — and never learns whose API answered. A driver that needs a call to answer and one that
 * needs none look identical from the effect's side, which is what makes the effect provable without a
 * provider at all.
 *
 * ## Null is an answer, and it is the one that must stay loud
 *
 * Null means the provider could not be asked, or answered something this package refuses to
 * interpret: several rates on one sale, a rate that is not a percentage, a rate finer than the
 * document can carry. The caller then issues NOTHING, exactly as it did before this contract
 * existed — the sale stays as visible as it was, and no document states a number nobody stands
 * behind. Swallowing the null and falling back on a derivation would give away the whole advantage:
 * a failed read is loud, a wrong computation is a plausible figure nobody questions.
 */
interface ReadsProviderComputedTax
{
    /**
     * The tax the provider applied to this sale, or null when it cannot be stated.
     *
     * @param  string  $saleReference  the provider's own id for the completed sale, as its event names it
     */
    public function forSale(string $saleReference): ?ProviderComputedTax;
}
