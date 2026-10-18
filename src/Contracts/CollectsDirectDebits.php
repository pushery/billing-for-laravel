<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Pushery\Billing\ValueObjects\Iban;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\SepaCreditor;

/**
 * A driver that draws SEPA direct debits, and registers a mandate the account holder gave on the application's
 * own form.
 *
 * A mandate given that way needs no payment: the account holder confirms the mandate text with their name and
 * IBAN, and the provider registers it. It is a sibling of {@see PaymentRails} rather than a method on it,
 * because `PaymentRails` has implementations outside this package, and a method added to it would be a fatal
 * error in code the package does not own.
 */
interface CollectsDirectDebits
{
    /** The payee the mandate names, with its creditor identifier. */
    public function creditor(): SepaCreditor;

    /**
     * Whether a charge against this mandate is drawn as a SEPA direct debit.
     *
     * A direct debit is announced to the payer before it is drawn, so the local engine asks this before every
     * charge it makes against a stored mandate.
     */
    public function drawsDirectDebit(MandateReference $mandate): bool;

    /**
     * Register the mandate an account holder gave on the application's form, and answer the reference to charge.
     *
     * The mandate exists when this returns. No payment is made.
     *
     * @param  string  $mandateReference  the reference the payer's bank shows beside every debit, at most 35 characters
     * @param  DateTimeInterface  $signedOn  the day the account holder confirmed the mandate
     */
    public function giveDirectDebitMandate(string $customerReference, string $accountHolder, Iban $iban, string $mandateReference, DateTimeInterface $signedOn): MandateReference;
}
