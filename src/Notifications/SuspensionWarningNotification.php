<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Pushery\Billing\ValueObjects\Money;

/**
 * The final warning sent while an account is delinquent, before a surface is locked out by the
 * suspension ladder. Carries the overdue amount. Localized via the publishable billing::notifications
 * namespace and non-suppressible — a suspension notice the customer must not be able to opt out of.
 */
final class SuspensionWarningNotification extends BillingNotification
{
    public function __construct(private readonly Money $amountDue) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.suspension_warning.subject'))
            ->line(__('billing::notifications.suspension_warning.intro'))
            ->line($this->amountDue->format())
            ->line(__('billing::notifications.suspension_warning.outro'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension_warning',
            'amount_due' => $this->amountDue->format(),
        ];
    }
}
