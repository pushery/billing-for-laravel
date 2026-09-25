<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Pushery\Billing\Models\SellerDataEscalationEpisode;
use Pushery\Billing\Notifications\SellerDataReminderNotification;

/**
 * Write down each channel a seller-data reminder was actually handed to.
 *
 * The reminder is the platform's proof that it asked, so the proof is taken from the delivery rather than
 * from the intent: this runs on the framework's own "sent" event, once per channel, after the channel
 * accepted the message. A reminder that was dispatched and never delivered leaves the time it was due on
 * the episode and nothing here, which is exactly what it should say.
 *
 * The recipient is recorded as `merchant` where the notice went to the episode's own seller, and as the
 * class otherwise. Never the identifier: the episode is kept after an erasure, unlinked, and an identifier
 * copied in here would keep it linked.
 */
final readonly class RecordSellerDataReminderDelivery
{
    public function __construct(private Repository $config) {}

    public function handle(NotificationSent $event): void
    {
        // The type first: this runs for every notification the application sends, and nearly all of them are
        // somebody else's.
        if (! $event->notification instanceof SellerDataReminderNotification
            || ! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return;
        }

        $episode = SellerDataEscalationEpisode::model()::query()->find($event->notification->episodeId);

        if (! $episode instanceof SellerDataEscalationEpisode) {
            return;
        }

        $notifiable = $event->notifiable;
        $key = $notifiable instanceof Model ? $notifiable->getKey() : null;
        $toTheSeller = $notifiable instanceof Model
            && $notifiable->getMorphClass() === $episode->merchant_type
            && (is_int($key) || is_string($key))
            && (string) $key === (string) $episode->merchant_id;

        $episode->deliveries = [...($episode->deliveries ?? []), [
            'reminder' => $event->notification->reminder->value,
            'channel' => $event->channel,
            'recipient' => $toTheSeller ? 'merchant' : (is_object($notifiable) ? $notifiable::class : 'unknown'),
            'at' => CarbonImmutable::now()->toIso8601String(),
        ]];
        $episode->save();
    }
}
