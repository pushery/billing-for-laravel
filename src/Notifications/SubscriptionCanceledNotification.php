<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The confirmation sent when a subscription is canceled, stating the date access runs until (the end
 * of the paid period / grace). Localized via the publishable billing::notifications namespace and
 * non-suppressible. The date is a plain ISO date — locale formatting is presentation.
 */
final class SubscriptionCanceledNotification extends BillingNotification
{
    public function __construct(private readonly DateTimeInterface $accessEndsAt) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.subscription_canceled.subject'))
            ->line(__('billing::notifications.subscription_canceled.intro'))
            ->line($this->accessEndsAt->format('Y-m-d'))
            ->line(__('billing::notifications.subscription_canceled.outro'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_canceled',
            'access_ends_at' => $this->accessEndsAt->format('Y-m-d'),
        ];
    }
}
