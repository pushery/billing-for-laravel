<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Resources\Payment;
use Pushery\Billing\ValueObjects\PaymentInstructions;

/**
 * The instructions of an open Mollie bank transfer, read from the payment's `details`.
 *
 * Mollie names them as soon as it creates a `banktransfer` payment: the name the transfer goes to (`bankName`), the
 * IBAN (`bankAccount`), the BIC (`bankBic`) and the reference it generated (`transferReference`), which the customer
 * gives as it stands. Once the payment is no longer open, its details describe how it ended, so instructions are read
 * only while it is.
 */
final readonly class MolliePaymentInstructions
{
    /** Mollie's id of the bank transfer method. */
    public const string METHOD = 'banktransfer';

    public static function of(Payment $payment): ?PaymentInstructions
    {
        $details = $payment->details;

        if (MollieValue::of($payment->method) !== self::METHOD || ! $payment->isOpen() || ! is_object($details)) {
            return null;
        }

        $reference = MollieValue::of($details->transferReference ?? null);
        $iban = MollieValue::of($details->bankAccount ?? null);

        if ($reference === null || $reference === '' || $iban === null || $iban === '') {
            return null;
        }

        $bic = MollieValue::of($details->bankBic ?? null);
        $recipient = MollieValue::of($details->bankName ?? null);

        return new PaymentInstructions(
            $reference,
            $iban,
            $bic === '' ? null : $bic,
            $recipient === '' ? null : $recipient,
        );
    }
}
