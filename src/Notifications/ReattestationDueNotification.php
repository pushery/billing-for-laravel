<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * A creator's tax declaration is due for renewal, and the notice says by when.
 *
 * Two things make it usable: the DATE the declaration stops answering, and what happens then — selling and
 * payouts pause until the creator declares again. A reminder without the date is one the reader cannot
 * plan around, and one without the consequence reads as paperwork that can wait.
 *
 * It asks for something, so it carries a way to do it — but the screen where a creator renews their
 * standing is the platform's own, which this package does not have. The application names its route in
 * `billing.marketplace.tax_standing_route`; without one, or with a name no route answers to, the notice
 * says the same without a button rather than linking somewhere that cannot take the answer.
 */
final class ReattestationDueNotification extends BillingNotification
{
    public function __construct(
        private readonly CarbonInterface $holdFrom,
        private readonly bool $lastReminder,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $date = ['date' => $this->holdFrom->toDateString()];

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.reattestation_due.subject'))
            ->line(Lang::get(
                $this->lastReminder
                    ? 'billing::notifications.reattestation_due.intro_last'
                    : 'billing::notifications.reattestation_due.intro_due',
                $date,
            ))
            ->line(Lang::get('billing::notifications.reattestation_due.consequence', $date));

        $route = Container::getInstance()->make(Repository::class)->get('billing.marketplace.tax_standing_route');

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.reattestation_due.cta'),
            is_string($route) && $route !== '' ? $this->actionUrl($route) : null,
        )->line(Lang::get('billing::notifications.reattestation_due.outro'));
    }

    /** @return array<string, bool|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reattestation_due',
            'hold_from' => $this->holdFrom->toDateString(),
            'last_reminder' => $this->lastReminder,
        ];
    }
}
