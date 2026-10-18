<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What an account holder entered and confirmed on the application's own direct debit form.
 *
 * `reference` and `statement` come from the {@see SepaMandateText} the form showed: the reference it named and
 * the text it displayed. The mandate is registered only when the text the package would put on the mandate now
 * is still that text, so a wording or a payee changed between showing and confirming never lands on a mandate
 * the account holder did not read.
 */
final readonly class DirectDebitConsent
{
    /**
     * @param  string  $country  the payer's country as an ISO 3166 code, which decides the language of the mandate
     * @param  ?string  $notifyEmail  where the notices of upcoming debits go; null sends them to the owner's own channel
     * @param  ?string  $ip  the address the confirmation came from, kept with the consent
     * @param  ?string  $userAgent  the browser the confirmation came from, kept with the consent
     */
    public function __construct(
        public string $accountHolder,
        public string $iban,
        public string $country,
        public string $reference,
        public string $statement,
        public ?string $notifyEmail = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
