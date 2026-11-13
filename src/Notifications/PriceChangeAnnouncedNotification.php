<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedDate;

/**
 * The announcement of a declared price change to a subscription it reaches: from which day the new price applies, what
 * changes in the words the change was declared with, and that the customer can cancel to that day.
 *
 * What changes is the application's own text, in the language the mail is rendered in, or in English where the change
 * says nothing in that one. The day is read in the owner's zone, as every day a notice names.
 */
final class PriceChangeAnnouncedNotification extends BillingNotification
{
    /**
     * @param  array<string, string>  $texts  what changes, by language, English among them
     */
    public function __construct(
        private readonly string $priceChange,
        private readonly array $texts,
        private readonly DateTimeInterface $effectiveOn,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $locale = Lang::getLocale();
        $date = LocalizedDate::long($this->effectiveOn);

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.price_change.subject'))
            ->line(Lang::get('billing::notifications.price_change.intro', ['date' => $date]))
            ->line($this->texts[$locale] ?? $this->texts[substr($locale, 0, 2)] ?? $this->texts['en'] ?? '')
            ->line(Lang::get('billing::notifications.price_change.cancel'));

        return $this->withAction($mail, Lang::get('billing::notifications.price_change.cta'), $this->actionFor($notifiable, BillingAction::Plan));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'price_change',
            'price_change' => $this->priceChange,
            'effective_on' => $this->effectiveOn->format('Y-m-d'),
        ];
    }
}
