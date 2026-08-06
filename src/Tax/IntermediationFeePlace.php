<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\ValueObjects\FeePlacement;

/**
 * Where an intermediation fee is taxed when the thing it intermediated is not a taxable supply at all.
 *
 * ## The anchor that is not there
 *
 * The ordinary rule anchors a fee for arranging a supply to the place of THAT supply. It is a good rule and
 * it has a hole: a sale between private people is not a taxable supply, so there is no place to anchor to.
 * An engine that reads the anchor from an underlying-supply object simply has no object here.
 *
 * ## The two wrong answers, which are wrong in opposite directions
 *
 * **"No supply was intermediated, so the fee is not taxable."** This is the tempting one, because it follows
 * from the missing anchor by pure logic. It is also how a platform stops charging tax on its own service —
 * a service it definitely performed and definitely owes tax on. The fee is the platform's supply to its
 * user; whether the users' sale was taxable has nothing to do with it.
 *
 * **"No anchor, so use the buyer's country."** This one looks careful — it charges tax, to a real country,
 * at a real rate. And it routes a service that belongs in the domestic return into a cross-border consumer
 * scheme, which corrupts the population of two returns rather than the amount of one line. Nothing about
 * either document looks wrong.
 *
 * ## The fallback
 *
 * Where the goods are, or where their transport began. It is the one fact that exists in every case here —
 * the goods are real even when the supply of them is not taxable — and it puts the fee in the return where
 * a service performed there belongs.
 */
final readonly class IntermediationFeePlace
{
    /**
     * Where the fee is taxed.
     *
     * @param  ?string  $intermediatedSupplyCountry  where the supply that was arranged is taxed, if it is
     *                                               taxable at all — null when there is nothing to anchor to
     * @param  ?string  $goodsCountry  where the goods are, or where their transport began
     */
    public function place(?string $intermediatedSupplyCountry, ?string $goodsCountry): FeePlacement
    {
        $anchored = $this->normalize($intermediatedSupplyCountry);

        if ($anchored !== null) {
            return new FeePlacement($anchored, false, true);
        }

        $fallback = $this->normalize($goodsCountry);

        // Nothing to anchor to and nothing to fall back on. Refusing to answer is the only honest option:
        // both available guesses charge the wrong country, and one of them charges nobody at all.
        if ($fallback === null) {
            return new FeePlacement(null, false, false);
        }

        // Taxable, in the country the goods are in — and deliberately NOT through a cross-border consumer
        // scheme, which is where a buyer-country fallback would have sent it.
        return new FeePlacement($fallback, false, true);
    }

    private function normalize(?string $country): ?string
    {
        $normalized = strtoupper(trim((string) $country));

        return $normalized === '' ? null : $normalized;
    }
}
