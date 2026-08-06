<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A booking batch that cannot be exported as it stands.
 *
 * Both cases refuse rather than emit, and for the same reason: the import that reads this file is not going
 * to argue. A document reference the format cannot carry is silently truncated on the way in, leaving a
 * booking whose reference points at nothing; a batch spanning two posting periods is accepted whole and
 * lands half of it in the wrong month. Neither surfaces as an error anywhere — they surface as a
 * reconciliation that does not close, months later, with nothing to point at.
 */
final class InvalidDatevBatch extends RuntimeException
{
    /** The permitted characters of a document-reference field, per the format description. */
    public const string REFERENCE_ALPHABET = 'A-Za-z0-9$&%*+\-/';

    public static function referenceTooLong(string $reference, int $limit): self
    {
        return new self(
            'The document reference "'.$reference.'" is '.mb_strlen($reference).' characters; the field '
            ."carries {$limit}. It is refused rather than trimmed: the import would accept a shortened "
            .'reference without complaint, and the booking would then point at a document nobody can find.'
        );
    }

    public static function referenceHasForbiddenCharacter(string $reference): self
    {
        return new self(
            'The document reference "'.$reference.'" contains a character the field cannot carry. Permitted '
            .'are letters, digits and $ & % * + - / — anything else is dropped or mangled by the import, '
            .'which turns a valid reference into one that resolves to nothing.'
        );
    }

    public static function spansPostingPeriods(string $from, string $to): self
    {
        return new self(
            "A batch covers one posting period; {$from} to {$to} spans more than one. Export each period on "
            .'its own instead — a batch that crosses a month boundary posts part of itself into the wrong '
            .'month, and the import accepts it whole.'
        );
    }

    /**
     * The only two shapes the due-date field may have in an emitted batch: absent, or a quoted six-digit
     * day-month-year.
     *
     * The field is shared — it carries either a second document number or a payment-processing date — and
     * this package reserves it for the date. The reservation is worth pinning because the tempting misuse is
     * to park some other short identifier there, and the import would read that as a document number and
     * settle the wrong open item with it. Asserted over every row of a produced batch; six digits is the only
     * thing read as a date.
     */
    public const string DUE_DATE_PATTERN = '/^(|"\d{6}")$/';

    public static function sachverhaltMustNotBeZero(string $account, string $configured): self
    {
        return new self(
            'The reverse-charge account '.$account.' is configured with the transaction key "'.$configured
            .'". The format description states the value 0 is not permitted, and the key is a three-digit '
            .'number from the DATEV catalog — confirm it with the tax advisor rather than guessing, the '
            .'same as the account number itself.'
        );
    }
}
