<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The nudge sent when an owner's default card is about to expire — the biggest single source of
 * involuntary churn, and the one that is entirely preventable. Queued so a scan of many owners never
 * blocks on the mail transport. Localized via the publishable billing::notifications namespace.
 */
final class CardExpiringNotification extends BillingNotification
{
    public function __construct(private readonly PaymentMethod $method) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.card_expiring.subject'))
            ->line(__('billing::notifications.card_expiring.intro', ['card' => $this->method->label()]))
            ->line(__('billing::notifications.card_expiring.outro'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'card_expiring',
            'card' => $this->method->label(),
            'expires_at' => $this->method->expiresAt()?->format('Y-m'),
        ];
    }
}
