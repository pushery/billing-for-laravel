<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Confirms the subscription is live and names the tier the customer is now on. It is not the receipt —
 * that is a separate, financial document about the money; this is the one that says what they actually
 * bought is switched on, which is the thing they were waiting for at the end of checkout.
 *
 * Localized via the publishable billing::notifications namespace and, like every billing notice,
 * non-suppressible.
 */
final class SubscriptionActivatedNotification extends BillingNotification
{
    public function __construct(private readonly string $tierLabel) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.subscription_activated.subject'))
            ->line(__('billing::notifications.subscription_activated.intro', ['tier' => $this->tierLabel]))
            ->line(__('billing::notifications.subscription_activated.outro'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_activated',
            'tier' => $this->tierLabel,
        ];
    }
}
