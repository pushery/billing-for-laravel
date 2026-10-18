<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The SEPA direct debit mandate an account holder reads and confirms, in one language.
 *
 * A form shows the parts: the heading, the particulars the rulebook asks the payee to state, the authorization
 * with the refund notice, and the agreed notice period beside it. {@see self::statement()} joins them into the
 * one text the consent record keeps, and the text a confirmation is compared against before the mandate is
 * registered.
 */
final readonly class SepaMandateText
{
    /**
     * @param  string  $language  the language the text is written in, as an ISO 639-1 code
     * @param  string  $reference  the mandate reference the text names
     * @param  list<array{label: string, value: string}>  $particulars  payee, creditor identifier, reference and payment type
     * @param  list<string>  $paragraphs  the authorization and the refund notice, each its own paragraph
     * @param  string  $agreement  the notice period agreed beside the mandate, never inside it
     * @param  int  $noticeDays  the days a debit is announced before it is drawn, as the agreement states them
     */
    public function __construct(
        public string $language,
        public string $reference,
        public string $heading,
        public array $particulars,
        public array $paragraphs,
        public string $agreement,
        public int $noticeDays,
    ) {}

    /** Everything the account holder reads, as one text, in the order a form shows it. */
    public function statement(): string
    {
        $lines = [$this->heading, ''];

        foreach ($this->particulars as $particular) {
            $lines[] = $particular['label'].': '.$particular['value'];
        }

        return implode("\n", [...$lines, '', ...$this->paragraphs, '', $this->agreement]);
    }
}
