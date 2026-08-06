<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * An objection was raised against a document that is not a self-billed one, so there is nothing to object to.
 *
 * The right to object exists because the platform wrote the creator's invoice FOR them — a self-billed
 * invoice or settlement note. An ordinary fan invoice is not one of those: nobody wrote it on someone else's
 * behalf, so the objection right does not attach to it. Refused rather than recorded as a no-op, so a caller
 * pointing at the wrong document learns it now.
 */
final class NotASelfBilledDocument extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Only a self-billed document (a Gutschrift or settlement note) can be objected to — the objection '
            .'right exists because the platform wrote the invoice for the creator. An ordinary invoice carries '
            .'no such right, so objecting to it is a caller mistake, not a state to record.'
        );
    }
}
