<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;

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
        return new MailMessage()
            ->subject(__('billing::notifications.trial_ending.subject'))
            ->line(__('billing::notifications.trial_ending.intro'))
            ->line($this->trialEndsAt->format('Y-m-d'))
            ->line(__('billing::notifications.trial_ending.outro'));
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
