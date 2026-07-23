<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * A correction snapshot was built in a state EN 16931 does not permit. Thrown at construction so a bad
 * correction never reaches persistence or an e-invoice writer — the invariant is enforced where the
 * document is made, not where it is rendered.
 */
final class InvalidInvoiceCorrection extends InvalidArgumentException
{
    public static function amendmentWithoutReference(): self
    {
        return new self(
            'An amendment (Rechnungsberichtigung, type code 384) must reference the invoice it corrects '
            .'(BG-3): pass the credited invoice\'s provider id or number. A correction with no origin '
            .'reference is only valid as a cancellation (type code 381).'
        );
    }

    public static function negativeAmount(string $field, int $value): self
    {
        return new self(
            "A correction carries positive magnitudes — the document's nature, not a sign, inverts the "
            ."meaning. Got {$field} = {$value}. Pass the absolute amount being corrected."
        );
    }
}
