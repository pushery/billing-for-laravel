<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Pushery\Billing\ValueObjects\Money;

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
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.payment_succeeded.subject'))
            ->line(__('billing::notifications.payment_succeeded.intro'))
            ->line($this->amount->format().' · '.$this->invoiceReference)
            ->line(__('billing::notifications.payment_succeeded.outro'));
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
