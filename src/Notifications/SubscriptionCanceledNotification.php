<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

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
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.subscription_canceled.subject'))
            ->line(Lang::get('billing::notifications.subscription_canceled.intro'))
            ->line($this->accessEndsAt->format('Y-m-d'))
            ->line(Lang::get('billing::notifications.subscription_canceled.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.subscription_canceled.cta'),
            $this->actionUrl('billing.account.plan'),
        );
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
