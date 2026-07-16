<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Pushery\Billing\ValueObjects\Money;

/**
 * The dunning notice sent when a payment fails. Localized via the publishable billing::notifications
 * namespace (informal, overridable) and deliberately plain — no channel-preference gate, because a
 * billing-critical notice is non-suppressible. Sent once per failing INVOICE, not once per provider
 * retry of it (see SendDunningNotice).
 *
 * Queued AFTER COMMIT: the effect run that sends it claims, sends and marks itself in one transaction,
 * so deferring the mail to after that commits means a run which rolled back can never have mailed the
 * customer — and a run that failed before committing is retried, so the notice is never silently lost.
 */
final class PaymentFailedNotification extends BillingNotification
{
    public function __construct(
        private readonly Money $amount,
        private readonly string $invoiceReference,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.payment_failed.subject'))
            ->line(__('billing::notifications.payment_failed.intro'))
            ->line($this->amount->format().' · '.$this->invoiceReference)
            ->line(__('billing::notifications.payment_failed.outro'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'amount' => $this->amount->format(),
            'invoice' => $this->invoiceReference,
        ];
    }
}
