<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * The base every billing notice extends. It carries the three things that are the same for all of them,
 * so no individual notice can get them wrong:
 *
 * 1. **Queued AFTER COMMIT.** A webhook effect claims, sends and marks itself handled in ONE transaction;
 *    deferring the mail to the commit is what guarantees a run that rolled back cannot have mailed the
 *    customer, and a run that failed before committing is retried rather than silently losing the notice.
 *
 * 2. **Where it goes** — {@see via()}. Mail by default; an app that keeps an in-app feed switches on
 *    `database` in `config('billing.notifications.channels')`. Every billing notice already carries a
 *    `toArray()` payload, so the database channel works the moment it is turned on.
 *
 * 3. **What it is** — {@see category()} / {@see isSuppressible()}. A billing notice is TRANSACTIONAL (a
 *    failed payment, a receipt, a trial about to end, an account about to be suspended). It is therefore
 *    NON-SUPPRESSIBLE: a preference screen must never offer to switch it off, because the customer would
 *    be opting out of being told their money did not move. That decision is made once, here, and is final
 *    — an individual notice cannot opt itself out of it.
 *
 * The customer's language is Laravel's own job: a notifiable implementing `HasLocalePreference` gets its
 * mail rendered in its stored locale, and every string here resolves through the publishable, overridable
 * `billing::notifications` namespace.
 */
abstract class BillingNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * The channels this notice goes out on — `['mail']` unless the app configures otherwise. An
     * unusable / empty configuration falls back to mail rather than sending nothing: a billing notice
     * silently going nowhere is worse than one arriving on the default channel.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = app(Repository::class)->get('billing.notifications.channels');

        if (! is_array($channels)) {
            return ['mail'];
        }

        $channels = array_values(array_filter($channels, is_string(...)));

        return $channels === [] ? ['mail'] : $channels;
    }

    /** The notification category. Always `billing` — see the class docblock. */
    final public function category(): string
    {
        return 'billing';
    }

    /** Whether a preference screen may switch this notice off. Always false — see the class docblock. */
    final public function isSuppressible(): bool
    {
        return false;
    }
}
