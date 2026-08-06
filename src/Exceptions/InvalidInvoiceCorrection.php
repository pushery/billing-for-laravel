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

    /**
     * The original's party could not be resolved, so the standing behind the restated tax cannot be checked.
     *
     * Fail-closed rather than best-effort: a correcting document that states tax names somebody who then
     * carries what it says. An unprovable permission is not a permission, and the alternative would be to
     * state tax on behalf of a party nobody can name.
     */
    public static function partyUnresolvable(string $original): self
    {
        return new self(sprintf(
            'The document being corrected [%s] has no resolvable owner, so the standing that must permit the '
            .'tax it restates cannot be read. A correcting document that states tax passes the same '
            .'disclosure whitelist the original passed, at the original\'s own moment -- and a party that '
            .'cannot be identified cannot be shown to have permitted it.',
            $original,
        ));
    }

    public static function negativeAmount(string $field, int $value): self
    {
        return new self(
            "A correction carries positive magnitudes — the document's nature, not a sign, inverts the "
            ."meaning. Got {$field} = {$value}. Pass the absolute amount being corrected."
        );
    }
}
