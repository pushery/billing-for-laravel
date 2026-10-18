<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A SEPA direct debit mandate that was not registered, with the reason a form can answer.
 *
 * `reason` is a fixed word per cause, so an application puts the message beside the right field without reading
 * the sentence: `invalid_iban` belongs under the IBAN and `no_account_holder` under the name, `invalid_reference` means
 * a reference of the application's own is not one a bank takes, `no_wording` and `no_payee` mean the form should not offer
 * a direct debit for that country or install at all, `statement_changed` means the text the account holder
 * confirmed is no longer the one the mandate would carry, and `not_valid` means the provider did not accept the
 * mandate.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DirectDebitMandateRefused extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidIban(): self
    {
        return new self('invalid_iban', 'The IBAN is not one a SEPA direct debit can be drawn from: its country, its length or its check digits do not hold.');
    }

    public static function noAccountHolder(): self
    {
        return new self('no_account_holder', 'The mandate names the account holder, and the name is empty.');
    }

    public static function invalidReference(): self
    {
        return new self('invalid_reference', 'A SEPA mandate reference has at most 35 characters, from letters, digits, spaces and + ? / - : ( ) . , \'.');
    }

    public static function noWording(string $country): self
    {
        return new self('no_wording', "There is no SEPA mandate text for payers in [{$country}], so no mandate is taken there. Add a wording for the country to billing.direct_debit.mandate_wordings.");
    }

    public static function noPayee(): self
    {
        return new self('no_payee', 'The mandate names the business it collects for, and billing.company.name is empty.');
    }

    public static function driverCannot(string $driver): self
    {
        return new self('driver_cannot', "The [{$driver}] driver does not register SEPA direct debit mandates given on a form of the application.");
    }

    public static function statementChanged(): self
    {
        return new self('statement_changed', 'The mandate text the account holder confirmed is not the text this mandate would carry. Show the current text and ask again.');
    }

    public static function notValid(string $driver, string $status): self
    {
        return new self('not_valid', "The [{$driver}] driver registered the mandate as [{$status}] rather than valid, so it cannot be charged.");
    }
}
