<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifiers;

use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Consumer\WithdrawalConsentLedger;
use Pushery\Billing\Contracts\DunningNotifier;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Contracts\PaymentActionNotifier;
use Pushery\Billing\Contracts\PriceChangeNotifier;
use Pushery\Billing\Contracts\ReceiptNotifier;
use Pushery\Billing\Contracts\SubscriptionEndingNotifier;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Contracts\SuspensionNotifier;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Contracts\UsageNotifier;
use Pushery\Billing\Notifications\PaymentActionRequiredNotification;
use Pushery\Billing\Notifications\PaymentFailedNotification;
use Pushery\Billing\Notifications\PaymentMethodRemovedNotification;
use Pushery\Billing\Notifications\PaymentSucceededNotification;
use Pushery\Billing\Notifications\PriceChangeAnnouncedNotification;
use Pushery\Billing\Notifications\QuotaWarningNotification;
use Pushery\Billing\Notifications\SubscriptionActivatedNotification;
use Pushery\Billing\Notifications\SubscriptionCanceledNotification;
use Pushery\Billing\Notifications\SubscriptionEndingNotification;
use Pushery\Billing\Notifications\SuspensionWarningNotification;
use Pushery\Billing\Notifications\TrialEndingNotification;
use Pushery\Billing\PriceChanges\PriceChange;
use Pushery\Billing\Support\FirstChargePreview;
use Pushery\Billing\Support\OwnersZone;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * The default notification delivery for the package: the dunning, receipt, suspension, mandate, trial and
 * cancellation notices, all through Laravel's notification stack. It goes via the Notification facade rather
 * than $owner->notify(), so the owner needs no notify() of its own. The channels still ask the owner where a
 * notice goes: mail through routeNotificationFor(), the database channel through notifications(), both of
 * which Laravel's Notifiable provides. On an owner model without them each queued notice fails, and
 * `billing:doctor` names such an owner type. The
 * once-per-failure / once-per-payment / once-per-rung / once-per-trial-end / once-per-cancellation
 * guarantees live in the callers (the webhook effects and the dunning-advance command); this simply delivers.
 *
 * The three notices that name a last day, of a trial, of the access after a cancellation and of a canceled
 * subscription, get their moment in the zone the owner reads dates in ({@see OwnerTimezone}), so the day they name
 * is the owner's day.
 */
final class LaravelDunningNotifier implements DunningNotifier, MandateNotifier, PaymentActionNotifier, PriceChangeNotifier, ReceiptNotifier, SubscriptionEndingNotifier, SubscriptionNotifier, SuspensionNotifier, TrialNotifier, UsageNotifier
{
    public function paymentFailed(Model $owner, Money $amount, string $invoiceReference): void
    {
        Notification::send($owner, new PaymentFailedNotification($amount, $invoiceReference));
    }

    public function paymentActionRequired(Model $owner, string $invoiceReference): void
    {
        Notification::send($owner, new PaymentActionRequiredNotification);
    }

    /**
     * The receipt — and, where one was given, the confirmation the law wants on a durable medium.
     *
     * The declarations are fetched here rather than passed in, and that is what keeps the seam intact.
     * `ReceiptNotifier` has one method and is implemented outside this package; a new required parameter
     * would be a fatal error in code this package does not own. Looking them up means the shipped notifier
     * carries them without any adopter changing a line, and an adopter with their own notifier decides
     * deliberately whether to do the same rather than losing them silently.
     *
     * Null on every install with no consumer-rights profile, and on every purchase that is not an add-on —
     * which is to say almost all of them. The notification then renders exactly what it always did.
     */
    public function paymentSucceeded(Model $owner, Money $amount, string $reference): void
    {
        Notification::send($owner, new PaymentSucceededNotification(
            $amount,
            $reference,
            Container::getInstance()->make(WithdrawalConsentLedger::class)->forPayment($owner, $reference),
        ));
    }

    public function suspensionWarning(Model $owner, Money $lateFee): void
    {
        Notification::send($owner, new SuspensionWarningNotification($lateFee));
    }

    public function mandateRevoked(Model $owner, string $mandateReference): void
    {
        Notification::send($owner, new PaymentMethodRemovedNotification);
    }

    /**
     * The reminder names the charge that follows the trial where the driver can preview it, and the day it is collected
     * in the owner's zone, as it names the end of the trial.
     */
    public function trialEnding(Model $owner, DateTimeInterface $endsAt): void
    {
        $charge = Container::getInstance()->make(FirstChargePreview::class)->for($owner);

        Notification::send($owner, new TrialEndingNotification(
            OwnersZone::of($owner, $endsAt),
            $charge instanceof UpcomingInvoicePreview ? new UpcomingInvoicePreview(OwnersZone::of($owner, $charge->date), $charge->amount) : null,
        ));
    }

    public function priceChangeAnnounced(Model $owner, PriceChange $change, DateTimeInterface $effectiveOn): void
    {
        // A day, not a moment: it is named as declared, so the owner's zone cannot move it to the evening before.
        Notification::send($owner, new PriceChangeAnnouncedNotification($change->key, $change->texts(), $effectiveOn));
    }

    public function subscriptionCanceled(Model $owner, DateTimeInterface $accessEndsAt): void
    {
        Notification::send($owner, new SubscriptionCanceledNotification(OwnersZone::of($owner, $accessEndsAt)));
    }

    public function subscriptionEnding(Model $owner, DateTimeInterface $endsAt, ?MerchantScope $merchant = null, ?string $merchantName = null): void
    {
        Notification::send($owner, new SubscriptionEndingNotification(OwnersZone::of($owner, $endsAt), $merchant, $merchantName));
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
