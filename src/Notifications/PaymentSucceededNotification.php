<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * The payment receipt sent when a charge settles. Localized via the publishable
 * billing::notifications namespace and non-suppressible, matching the dunning notice: a receipt is a
 * billing record the customer is entitled to, not a marketing message they opt out of.
 */
final class PaymentSucceededNotification extends BillingNotification
{
    public function __construct(
        private readonly Money $amount,
        private readonly string $invoiceReference,
        /**
         * The buyer's pre-purchase declarations, when this payment had any.
         *
         * The receipt is where the law wants them repeated on a durable medium, and the right of withdrawal
         * ends ONLY once that confirmation exists. A declaration collected, recorded, and never confirmed
         * leaves the right standing — which is the most expensive failure on this path, because everything
         * about it looks like success: the ledger holds the consent, the gate lets the provision through,
         * and the buyer can still withdraw for fourteen days.
         *
         * Null on every install with no consumer-rights profile. The mail is then byte-for-byte what it
         * always was.
         */
        private readonly ?WithdrawalConsent $consent = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.payment_succeeded.subject'))
            ->line(Lang::get('billing::notifications.payment_succeeded.intro'))
            ->line($this->amount->format().' · '.$this->invoiceReference);

        // The WORDING, not a link to it. A receipt pointing at "our withdrawal policy" confirms nothing:
        // the linked text changes and the purchase does not, so what would arrive years later is whatever
        // the page says then. Both or neither -- one of the two reads as a complete confirmation and is not.
        if ($this->consent instanceof WithdrawalConsent && $this->consent->hasWording()) {
            $mail->line(Lang::get('billing::notifications.payment_succeeded.declarations'))
                ->line((string) $this->consent->immediateProvisionNotice)
                ->line((string) $this->consent->forfeitureNotice);
        }

        return $this->withAction(
            $mail->line(Lang::get('billing::notifications.payment_succeeded.outro')),
            Lang::get('billing::notifications.payment_succeeded.cta'),
            $this->actionUrl('billing.account.invoices'),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_succeeded',
            'amount' => $this->amount->format(),
            'invoice' => $this->invoiceReference,
        ];
    }
}
