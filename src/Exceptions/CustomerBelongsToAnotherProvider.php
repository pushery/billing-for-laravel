<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A billable already registered with a different payment provider.
 *
 * The customer reference lives in one column, because that is what makes a webhook resolvable to an owner
 * without every effect knowing which driver is active. The cost of one column is this case: an install
 * that changes provider meets owners whose reference the new provider did not issue.
 *
 * It refuses instead of overwriting, and the difference is not cosmetic. Overwriting would silently
 * detach that owner from every record the old provider still holds — their live subscription there, their
 * mandates, the customer object their past invoices name — and the first symptom would be a webhook from
 * the old provider that resolves to nobody. Changing provider for an existing customer is a migration
 * somebody performs; it is not something a checkout button does on their behalf.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CustomerBelongsToAnotherProvider extends RuntimeException
{
    public static function forReference(string $provider, string $reference): self
    {
        return new self(
            'This billable already carries the customer reference "'.$reference.'", which '.$provider
            .' did not issue. It is refused rather than replaced: replacing it would detach the owner from '
            .'the live subscription, the mandates and the invoices the other provider still holds, and the '
            .'first sign of it would be a webhook from there that resolves to nobody. Moving an existing '
            .'customer between providers is a migration, not something a checkout does on their behalf.'
        );
    }
}
