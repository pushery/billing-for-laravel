<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when a payment method that could be charged off-session was removed — a detached card, a revoked
 * SEPA mandate. It nudges the owner to re-add one before the next renewal fails, and doubles as a security
 * confirmation ("a payment method was removed from your account"). Queued so a burst never blocks on the
 * mail transport. Localized via the publishable billing::notifications namespace.
 */
final class PaymentMethodRemovedNotification extends BillingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.payment_method_removed.subject'))
            ->line(__('billing::notifications.payment_method_removed.intro'))
            ->line(__('billing::notifications.payment_method_removed.outro'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'payment_method_removed'];
    }
}
