<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Warns the owner that a metered allowance is running out, while they can still do something about it —
 * top up, upgrade, or simply slow down. Without it the first thing a customer learns about their limit is
 * being refused by it, which is the support ticket this notice exists to prevent.
 *
 * Localized via the publishable billing::notifications namespace and, like every billing notice,
 * non-suppressible: running out of what you paid for is not marketing.
 */
final class QuotaWarningNotification extends BillingNotification
{
    public function __construct(
        private readonly string $meterKey,
        private readonly string $label,
        private readonly int $used,
        private readonly int $included,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('billing::notifications.quota_warning.subject', ['meter' => $this->label]))
            ->line(__('billing::notifications.quota_warning.intro', [
                'meter' => $this->label,
                'used' => $this->used,
                'included' => $this->included,
            ]))
            ->line(__('billing::notifications.quota_warning.outro'));
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
        ];
    }
}
