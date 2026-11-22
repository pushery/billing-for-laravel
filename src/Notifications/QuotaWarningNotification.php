<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedNumber;
use Pushery\Billing\Support\MarkdownText;

/**
 * Warns the owner that a metered allowance is running out, while they can still do something about it —
 * top up, upgrade, or slow down. Without it the first thing a customer learns about their limit is
 * being refused by it, which is the support request this notice exists to prevent.
 *
 * Localized via the publishable billing::notifications namespace and, like every billing notice,
 * non-suppressible: running out of what you paid for is not marketing. The quantities are written as
 * the language of the mail writes numbers, each with the unit the meter counts in where it names one,
 * and the label of the meter is what they are included in.
 */
final class QuotaWarningNotification extends BillingNotification
{
    public function __construct(
        private readonly string $meterKey,
        private readonly string $label,
        private readonly int $used,
        private readonly int $included,
        private readonly ?string $unit = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.quota_warning.subject', ['meter' => $this->label]))
            // The meter is named by the label the application configures, and the quantities carry the unit it
            // configures, so all three go into the Markdown line as text.
            ->line(MarkdownText::interpolate((string) Lang::get('billing::notifications.quota_warning.intro'), [
                'used' => $this->quantity($this->used),
                'included' => $this->quantity($this->included),
                'meter' => $this->label,
            ]))
            ->line(Lang::get('billing::notifications.quota_warning.outro'));

        return $this->withAction(
            $mail,
            Lang::get('billing::notifications.quota_warning.cta'),
            $this->actionFor($notifiable, BillingAction::Usage),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quota_warning',
            'meter' => $this->meterKey,
            'label' => $this->label,
            'used' => $this->used,
            'included' => $this->included,
            'unit' => $this->unit,
        ];
    }

    /** A quantity as the language of the mail writes it, with the unit of the meter where it names one. */
    private function quantity(int $amount): string
    {
        $number = LocalizedNumber::format($amount);

        return $this->unit === null ? $number : $number.' '.$this->unit;
    }
}
