<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifiers;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\DunningNotifier;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Contracts\PaymentActionNotifier;
use Pushery\Billing\Contracts\ReceiptNotifier;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Contracts\SuspensionNotifier;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Contracts\UsageNotifier;
use Pushery\Billing\Notifications\PaymentActionRequiredNotification;
use Pushery\Billing\Notifications\PaymentFailedNotification;
use Pushery\Billing\Notifications\PaymentMethodRemovedNotification;
use Pushery\Billing\Notifications\PaymentSucceededNotification;
use Pushery\Billing\Notifications\QuotaWarningNotification;
use Pushery\Billing\Notifications\SubscriptionActivatedNotification;
use Pushery\Billing\Notifications\SubscriptionCanceledNotification;
use Pushery\Billing\Notifications\SuspensionWarningNotification;
use Pushery\Billing\Notifications\TrialEndingNotification;
use Pushery\Billing\ValueObjects\Money;

/**
 * The default notification delivery for the package: the dunning, receipt, suspension, mandate, trial and
 * cancellation notices, all through Laravel's notification stack. It goes via the Notification facade rather
 * than $owner->notify() so any billing owner works, whether or not it uses the Notifiable trait. The
 * once-per-failure / once-per-payment / once-per-rung / once-per-trial-end / once-per-cancellation
 * guarantees live in the callers (the webhook effects and the dunning-advance command); this simply delivers.
 */
final class LaravelDunningNotifier implements DunningNotifier, MandateNotifier, PaymentActionNotifier, ReceiptNotifier, SubscriptionNotifier, SuspensionNotifier, TrialNotifier, UsageNotifier
{
    public function paymentFailed(Model $owner, Money $amount, string $invoiceReference): void
    {
        Notification::send($owner, new PaymentFailedNotification($amount, $invoiceReference));
    }

    public function paymentActionRequired(Model $owner, string $invoiceReference): void
    {
        Notification::send($owner, new PaymentActionRequiredNotification);
    }

    public function paymentSucceeded(Model $owner, Money $amount, string $reference): void
    {
        Notification::send($owner, new PaymentSucceededNotification($amount, $reference));
    }

    public function suspensionWarning(Model $owner, Money $amountDue): void
    {
        Notification::send($owner, new SuspensionWarningNotification($amountDue));
    }

    public function mandateRevoked(Model $owner, string $mandateReference): void
    {
        Notification::send($owner, new PaymentMethodRemovedNotification);
    }

    public function trialEnding(Model $owner, DateTimeInterface $endsAt): void
    {
        Notification::send($owner, new TrialEndingNotification($endsAt));
    }

    public function subscriptionCanceled(Model $owner, DateTimeInterface $accessEndsAt): void
    {
        Notification::send($owner, new SubscriptionCanceledNotification($accessEndsAt));
    }

    public function subscriptionActivated(Model $owner, string $tierLabel): void
    {
        Notification::send($owner, new SubscriptionActivatedNotification($tierLabel));
    }

    public function quotaWarning(Model $owner, string $meterKey, string $label, int $used, int $included): void
    {
        Notification::send($owner, new QuotaWarningNotification($meterKey, $label, $used, $included));
    }
}
