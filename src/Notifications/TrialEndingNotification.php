<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * The reminder sent as a free trial nears its end. Localized via the publishable
 * billing::notifications namespace and non-suppressible. The trial-end date is carried as a plain
 * ISO date — rich locale formatting is a presentation concern, not the notification's.
 *
 * Queued AFTER COMMIT, like every notification the package sends: the run that sends it claims, mails and
 * marks itself handled in one transaction, so a run that rolled back can never have mailed the customer.
 */
final class TrialEndingNotification extends BillingNotification
{
    public function __construct(private readonly DateTimeInterface $trialEndsAt) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.trial_ending.subject'))
            ->line(Lang::get('billing::notifications.trial_ending.intro'))
            ->line($this->trialEndsAt->format('Y-m-d'))
            ->line(Lang::get('billing::notifications.trial_ending.outro'));

        // Where the reader is sent depends on what they already have. Somebody with a card on file needs the
        // plan screen — they are choosing whether to continue. Somebody without one needs the screen that
        // takes a card, which is the action this mail's own text asks for. Sending both to the same place
        // makes one of the two do a second hop for no reason.
        $hasCard = is_string($notifiable->pm_type ?? null) && ($notifiable->pm_type ?? '') !== '';

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.trial_ending.cta'),
            $this->actionUrl($hasCard ? 'billing.account.plan' : 'billing.account.payment-methods'),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_ending',
            'ends_at' => $this->trialEndsAt->format('Y-m-d'),
        ];
    }
}
