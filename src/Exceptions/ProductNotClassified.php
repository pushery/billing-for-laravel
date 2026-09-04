<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A product was about to be sold without anybody having said what kind of thing it is.
 *
 * Refused, because there is no safe substitute. Every consequence of a sale — where it is taxed, at which
 * band, whether it is reportable, what the buyer may undo — follows from that one answer, so a missing
 * classification is not one unknown but five guesses. And guesses that happen to be right most of the time
 * are the hardest defects to find: nothing fails, the numbers look ordinary, and only the minority of sales
 * where the guess was wrong are wrong — quietly, in records nobody re-reads.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ProductNotClassified extends RuntimeException
{
    public static function beforeSale(): self
    {
        return new self(
            'This product has no archetype, so nothing about how a sale of it should be treated can be '
            .'established. Classify it before it becomes sellable: the alternative is not one unknown but '
            .'five guesses, each of which would be right often enough to look fine.'
        );
    }

    /**
     * A document is about to state a reverse charge without anybody having said whether it is goods.
     *
     * The narrow case, and the reason it is narrow: for a plain domestic supply the archetype changes nothing
     * a reader can see, so demanding it there would refuse documents to buy nothing. Under a REVERSE CHARGE
     * it changes the category the document carries — `K` with VATEX-EU-IC for an intra-Community supply of
     * goods, `AE` for a service — and those are two different statements about who accounts for the tax and
     * under which provision.
     *
     * With the column empty the renderer reads null as "not goods" and prints `AE`. That is right for a
     * digital supply and a wrong statement of the provision for every other, arrived at without the document
     * ever being asked. So the refusal lands at ISSUE, not at render: by render time the row exists and its
     * number is drawn from a gapless series, and a document number is not returned by declining to print it.
     */
    public static function beforeReverseChargedDocument(string $reference): self
    {
        return new self(
            "Cannot issue {$reference}: a reverse-charged supply must say whether it is goods or a service. "
            .'The document states K with VATEX-EU-IC for goods and AE for a service, and those name different '
            .'provisions. Left unclassified it would print AE without having been asked.'
        );
    }

    /**
     * A profile's taxonomy cell that should hold a place-of-supply rule holds something else.
     *
     * Worth refusing rather than defaulting: "taxed at the destination" is a plausible answer for almost
     * every product, so a fallback would hide the profile defect behind a correct-looking invoice — and it
     * would hide it on every sale, not just the odd one.
     */
    public static function forPlaceOfSupply(string $archetype): self
    {
        return new self(
            "The active jurisdiction profile does not classify where a \"{$archetype}\" supply is taxed. A "
            .'place-of-supply rule is not a detail that can be guessed: defaulting it would put a plausible '
            .'country on every invoice for this product and report them all into that country\'s return.'
        );
    }

    public static function delegatedWithoutReference(string $archetype): self
    {
        return new self(
            "A [{$archetype}] takes its treatment from whatever it was sold alongside, and nothing was named. "
            .'Name it rather than letting a default answer: treating it as a plain sale would under-report a '
            .'voluntary payment on reportable work, and guessing the other way would report one that is not '
            .'reportable — which is its own offense, not a cautious error.'
        );
    }

    /**
     * The archetype's treatment is not knowable yet, and that is a fact about the product rather than a gap.
     *
     * A multi-purpose voucher is the case: at the moment it is sold, nobody knows what it will buy, so
     * where it is taxed and at which band are genuinely undetermined until it is redeemed. Refusing here is
     * not caution — deciding would mean inventing a redemption that has not happened.
     */
    public static function deferredUntilRedemption(string $archetype): self
    {
        return new self(
            "A [{$archetype}] has no tax treatment yet: what it will buy is not decided at the moment it is "
            .'sold, so where it is taxed and at which band are undetermined until it is redeemed. Decide '
            .'this at redemption against what was actually bought, not here against a guess.'
        );
    }
}
