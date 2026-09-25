<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\ValueObjects\Money;

/**
 * The final warning sent while an account is delinquent, before a surface is locked out by the
 * suspension ladder. Localized via the publishable billing::notifications namespace and non-suppressible —
 * a suspension notice the customer must not be able to opt out of.
 *
 * It carries the late fee the rung added, and names it as a fee only when there is one. It used to print
 * that figure as the overdue amount, between "an overdue balance" and "settle the amount below", so every
 * rung without a fee asked the customer to settle 0.00 and a rung with one named the fee as the debt. The
 * amount that failed was named by the payment-failed notice that opened the arrears; this one leads to the
 * recovery screen, where the owner fixes the payment method the provider retries.
 */
final class SuspensionWarningNotification extends BillingNotification
{
    public function __construct(private readonly Money $lateFee) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.suspension_warning.subject'))
            ->line(Lang::get('billing::notifications.suspension_warning.intro'));

        if ($this->lateFee->isPositive()) {
            $mail->line(Lang::get('billing::notifications.suspension_warning.late_fee', ['amount' => $this->lateFee->format()]));
        }

        $mail->line(Lang::get('billing::notifications.suspension_warning.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.suspension_warning.cta'),
            $this->actionUrl('billing.account.recovery'),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension_warning',
            'late_fee' => $this->lateFee->isPositive() ? $this->lateFee->format() : null,
        ];
    }
}
