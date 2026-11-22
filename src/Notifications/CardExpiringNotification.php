<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\Support\MarkdownText;
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
        // The month the card runs out in, where the provider named it, so the owner knows by when to replace it.
        $month = LocalizedDate::monthAndYear($this->method->expiresAt());
        $intro = $month === null
            ? (string) Lang::get('billing::notifications.card_expiring.intro')
            : (string) Lang::get('billing::notifications.card_expiring.intro_dated', ['month' => $month]);

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.card_expiring.subject'))
            // A brand the package does not know is named by the key the provider sent, so the label goes into the
            // Markdown line as text.
            ->line(MarkdownText::interpolate($intro, ['card' => $this->method->label()]))
            ->line(Lang::get('billing::notifications.card_expiring.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.card_expiring.cta'),
            $this->actionFor($notifiable, BillingAction::PaymentMethods),
        );
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
