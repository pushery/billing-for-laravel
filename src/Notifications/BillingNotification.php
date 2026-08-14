<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

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
        $channels = Container::getInstance()->make(Repository::class)->get('billing.notifications.channels');

        if (! is_array($channels)) {
            return ['mail'];
        }

        $channels = array_values(array_filter($channels, is_string(...)));

        return $channels === [] ? ['mail'] : $channels;
    }

    /**
     * A link to one of the hub's own screens, or null where the app has not mounted it.
     *
     * The guard is the whole method. This package's account hub registers only `if (class_exists(Livewire))`,
     * and Livewire is a `suggest` — so a consumer installation genuinely may not have these routes, and a
     * bare `route()` in a mail would fatal while sending a notice about somebody's money. Null means "say it
     * without a button", which is what every one of these mails did before.
     *
     * It also decides where a notice can honestly point. A mail that says "add a payment method" and gives
     * the reader nowhere to do it asks them to find the screen themselves, and the operator never learns how
     * many did not — the mail was delivered, after all.
     */
    protected function actionUrl(string $route): ?string
    {
        return Route::has($route) ? URL::route($route) : null;
    }

    /**
     * A mail line plus a call to action, where one can be built.
     *
     * Written once here because eleven notices need the same two-branch shape, and eleven copies of it is
     * eleven places the guard above can be forgotten — which is the failure that produces a dead link in a
     * transactional mail rather than a missing one.
     */
    protected function withAction(MailMessage $mail, string $label, ?string $url): MailMessage
    {
        return $url === null ? $mail : $mail->action($label, $url);
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
