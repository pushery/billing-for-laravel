<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Support\LocalizedMoney;
use Pushery\Billing\ValueObjects\Money;

/**
 * The dunning notice sent when a payment fails. Localized via the publishable billing::notifications
 * namespace (informal, overridable) and deliberately plain — no channel-preference gate, because a
 * billing-critical notice is non-suppressible. Sent once per failing invoice, not once per provider
 * retry of it (see SendDunningNotice).
 *
 * Where the failure has paused the owner's access, the mail says so and says that access comes back with the
 * next payment that goes through. A failed payment that paused nothing, such as a one-off charge, keeps the
 * plain wording.
 *
 * Queued after the commit: the effect run that sends it claims, sends and marks itself in one transaction,
 * so deferring the mail to after that commits means a run which rolled back can never have mailed the
 * customer — and a run that failed before committing is retried, so the notice is never silently lost.
 */
final class PaymentFailedNotification extends BillingNotification
{
    public function __construct(
        private readonly Money $amount,
        private readonly string $invoiceReference,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $paused = $this->accessPaused($notifiable);

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.payment_failed.subject'))
            ->line(Lang::get('billing::notifications.payment_failed.intro'))
            ->line(Lang::get('billing::notifications.payment_failed.summary', [
                'amount' => LocalizedMoney::format($this->amount),
                'invoice' => $this->invoiceReference,
            ]));

        if ($paused) {
            $mail->line(Lang::get('billing::notifications.payment_failed.paused'));
        }

        $mail->line(Lang::get($paused
            ? 'billing::notifications.payment_failed.outro_paused'
            : 'billing::notifications.payment_failed.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.payment_failed.cta'),
            $this->actionFor($notifiable, BillingAction::Recovery),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'amount' => $this->amount->format(),
            'invoice' => $this->invoiceReference,
        ];
    }

    /**
     * Whether the owner's access is paused while the payment is outstanding.
     *
     * A subscription that is past due or incomplete grants no access, and the `billing.dunning` middleware
     * blocks the surfaces it guards for as long as it stays so. The mail asks the guard that middleware asks,
     * when the mail is built rather than when the failure arrived, so it names the pause the customer meets.
     * An owner that was never saved holds no subscription and pauses nothing.
     */
    private function accessPaused(object $notifiable): bool
    {
        if (! $notifiable instanceof Model || $notifiable->getKey() === null) {
            return false;
        }

        return Container::getInstance()->make(DunningGuard::class)->blockingState($notifiable) instanceof SubscriptionState;
    }
}
