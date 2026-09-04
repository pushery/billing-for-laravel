<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * A settlement document was about to state tax for a creator whose standing does not permit it.
 *
 * Thrown before the document is written, not after it is rendered — a renderer that refuses a finished
 * document comes too late, and the creator is the one who would owe the wrongly-stated tax. Issue the
 * status-correct variant, which states no tax, instead.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class TaxDisclosureNotPermitted extends RuntimeException
{
    public static function forStatus(CreatorTaxStatus $status, Money $taxAmount): self
    {
        return new self(sprintf(
            'A settlement document to a creator whose standing is [%s] may not state tax, but %s was about to '
            .'be disclosed. Only a validated, standard-rated domestic creator may be shown tax on a self-billed '
            .'document — for anyone else the recipient owes the tax it states. Issue the status-correct variant '
            .'with no tax statement instead.',
            $status->value,
            $taxAmount->format(),
        ));
    }
}
