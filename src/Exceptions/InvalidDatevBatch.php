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
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
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

    /**
     * A foreign-currency document that never froze a rate cannot be booked, and it is refused rather than
     * sent.
     *
     * DATEV's rule is that a row whose currency differs from the batch's base must carry the rate or the
     * base amount. Carrying neither is not a formatting lapse: the import either rejects the row outright or
     * books it AT FACE VALUE into a base-currency account — 500,00 PLN posted as 500,00 EUR — and the second
     * outcome overstates the revenue by the exchange rate while looking like a plausible figure the whole
     * way through.
     *
     * Refused rather than dropped, and the distinction is the whole argument. Dropping the row would hide
     * revenue, which is worse than a row an importer questions; that objection is right and it is about
     * dropping. A refused BATCH exports nothing and says why, so nothing is hidden, nothing is posted wrong,
     * and the missing freeze becomes the operator's next action instead of a reconciliation months later.
     *
     * What settled it against sending the row is that sending rests on the import QUESTIONING it, and this
     * package's own export comment says the import either rejects it or books it at face value. Correctness
     * that depends on somebody else's undefined behavior is not correctness.
     *
     * Deriving a rate here is the one option that stays off the table: that is the divergence the freeze
     * exists to prevent, and the books and the document would disagree with only the books re-derivable.
     */
    public static function foreignCurrencyWithoutAFrozenRate(string $reference, string $currency, string $base): self
    {
        return new self(
            'The document "'.$reference.'" is in '.$currency.' and the batch books in '.$base.', but no '
            .'document-layer rate was ever frozen on it. The batch is refused rather than exported: a row '
            .'carrying neither a rate nor a base amount is either rejected by the import or booked at face '
            .'value, and the second overstates the revenue by the exchange rate. Freeze the rate on the '
            .'document, or export a period that does not contain it.'
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
