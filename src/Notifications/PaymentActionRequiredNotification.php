<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * Prompts the customer to confirm a payment their bank held for authentication (3-D Secure). Nothing is
 * wrong with their card — the subscription just cannot start or renew until they tap "confirm", and
 * without this nudge it sits `incomplete` while they believe they subscribed. The CTA points at the
 * payment-recovery screen, which already handles the incomplete/confirm case.
 *
 * Localized via the publishable billing::notifications namespace and, like every billing notice,
 * non-suppressible: a payment stuck waiting on the customer is exactly the thing they need to hear about.
 */
final class PaymentActionRequiredNotification extends BillingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(Lang::get('billing::notifications.payment_action_required.subject'))
            ->line(Lang::get('billing::notifications.payment_action_required.intro'))
            ->line(Lang::get('billing::notifications.payment_action_required.outro'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'payment_action_required'];
    }
}
