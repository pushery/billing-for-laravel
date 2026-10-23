<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedMoney;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * Tells the owner that a cycle was paid with their fallback because the default could not pay it.
 *
 * Nothing is owed, so it is not a dunning notice. It is sent all the same, and like the dunning notice it cannot be
 * opted out of: the default still comes first for every later cycle, and it has just failed. It names the method
 * that was charged and leads to the screen where the default is changed.
 */
final class FallbackPaymentMethodChargedNotification extends BillingNotification
{
    public function __construct(
        private readonly Money $amount,
        private readonly PaymentMethod $method,
        private readonly string $reference,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.fallback_method_charged.subject'))
            ->line(Lang::get('billing::notifications.fallback_method_charged.intro'))
            ->line(LocalizedMoney::format($this->amount).' · '.$this->method->label().' · '.$this->reference)
            ->line(Lang::get('billing::notifications.fallback_method_charged.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.fallback_method_charged.cta'),
            $this->actionFor($notifiable, BillingAction::PaymentMethods),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'fallback_method_charged',
            'amount' => $this->amount->format(),
            'method' => $this->method->label(),
            'invoice' => $this->reference,
        ];
    }
}
