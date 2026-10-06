<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\Support\TrialCallouts;

/**
 * The reminder sent as a free trial nears its end. Localized via the publishable
 * billing::notifications namespace and non-suppressible. The mail names the trial-end date in the
 * application's language (`LocalizedDate::long()`), the database entry carries it as an ISO date, and both
 * read the day in the zone of the moment passed in, so the two never name different days.
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
            ->line(Lang::get('billing::notifications.trial_ending.intro', ['date' => LocalizedDate::long($this->trialEndsAt)]))
            ->line(Lang::get('billing::notifications.trial_ending.outro'));

        // Where the reader is sent depends on what they already have. Somebody with a card on file needs the
        // plan screen — they are choosing whether to continue. Somebody without one needs the screen that
        // takes a card, which is the action this mail's own text asks for. Sending both to the same place
        // makes one of the two do a second hop for no reason.
        $hasCard = $notifiable instanceof Model && TrialCallouts::hasPaymentMethod($notifiable);

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.trial_ending.cta'),
            $this->actionFor($notifiable, $hasCard ? BillingAction::Plan : BillingAction::PaymentMethods),
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
