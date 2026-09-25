<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\SellerDataEscalationStage;
use Pushery\Billing\Enums\SellerDataMeasure;

/**
 * A seller's record is still missing something, and the notice names what.
 *
 * The fields are named because "your details are incomplete" sends somebody hunting through a form. Where a
 * measure can follow, the notice gives its date and what it is; where none can, it asks and says nothing
 * more, because there is nothing more to say. A withholding is described as what it is: held, and paid out
 * in full once the details are complete.
 *
 * The screen where a seller completes their record is the platform's own. The application names its route
 * in `billing.marketplace.seller_record_route`; without one the notice says the same without a button.
 *
 * `episodeId` travels with the notice so that the channels it was handed to can be written back to the
 * episode it belongs to.
 */
final class SellerDataReminderNotification extends BillingNotification
{
    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(
        public readonly int $episodeId,
        public readonly SellerDataEscalationStage $reminder,
        private readonly array $missingFields,
        private readonly ?CarbonInterface $measureFrom,
        private readonly ?SellerDataMeasure $measure,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.seller_data_reminder.subject'))
            ->line(Lang::get(
                $this->reminder === SellerDataEscalationStage::SecondReminder
                    ? 'billing::notifications.seller_data_reminder.intro_second'
                    : 'billing::notifications.seller_data_reminder.intro_first',
                ['fields' => implode(', ', array_map($this->label(...), $this->missingFields))],
            ));

        if ($this->measure instanceof SellerDataMeasure && $this->measureFrom instanceof CarbonInterface) {
            $mail->line(Lang::get(
                match ($this->measure) {
                    SellerDataMeasure::WithholdPayout => 'billing::notifications.seller_data_reminder.consequence_withhold_payout',
                    SellerDataMeasure::SuspendSales => 'billing::notifications.seller_data_reminder.consequence_suspend_sales',
                },
                ['date' => $this->measureFrom->toDateString()],
            ));
        }

        $route = Container::getInstance()->make(Repository::class)->get('billing.marketplace.seller_record_route');

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.seller_data_reminder.cta'),
            is_string($route) && $route !== '' ? $this->actionUrl($route) : null,
        )->line(Lang::get('billing::notifications.seller_data_reminder.outro'));
    }

    /** @return array<string, bool|int|string|list<string>|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'seller_data_reminder',
            'reminder' => $this->reminder->value,
            'missing_fields' => $this->missingFields,
            'measure' => $this->measure?->value,
            'measure_from' => $this->measureFrom?->toDateString(),
        ];
    }

    /** A field's name as the seller reads it, or the name itself where no translation carries one. */
    private function label(string $field): string
    {
        $key = 'billing::notifications.seller_data_reminder.fields.'.$field;
        $label = Lang::get($key);

        return is_string($label) && $label !== $key ? $label : $field;
    }
}
