<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pushery\Billing\Contracts\BillingActionUrls;
use Pushery\Billing\Enums\BillingAction;

/**
 * The base every billing notice extends. It carries the four things that are the same for all of them,
 * so no individual notice can get them wrong:
 *
 * 1. **Queued after the commit.** A webhook effect claims, sends and marks itself handled in one transaction;
 *    deferring the mail to the commit is what guarantees a run that rolled back cannot have mailed the
 *    customer, and a run that failed before committing is retried rather than silently losing the notice.
 *
 * 2. **Where it goes** — {@see via()}. Mail by default; an app that keeps an in-app feed switches on
 *    `database` in `config('billing.notifications.channels')`. Every billing notice already carries a
 *    `toArray()` payload, so the database channel works the moment it is turned on.
 *
 * 3. **What it is** — {@see category()} / {@see isSuppressible()}. A billing notice is transactional (a
 *    failed payment, a receipt, a trial about to end, an account about to be suspended). It is therefore
 *    non-suppressible: a preference screen must never offer to switch it off, because the customer would
 *    be opting out of being told their money did not move. That decision is made once, here, and is final
 *    — an individual notice cannot opt itself out of it.
 *
 * 4. **Tried again after a passing failure** — {@see $tries} / {@see backoff()}. Three tries, a minute and
 *    then five minutes apart, unless `config('billing.notifications')` says otherwise, so a mail server's
 *    timeout or temporary refusal does not lose the notice.
 *
 * The customer's language is Laravel's own job: a notifiable implementing `HasLocalePreference` gets its
 * mail rendered in its stored locale, and every string here resolves through the publishable, overridable
 * `billing::notifications` namespace.
 */
abstract class BillingNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** How many times the queue tries a notice when the config names no positive whole number. */
    public const int TRIES = 3;

    /** The seconds before each retry when the config names no list of positive whole numbers. */
    public const array BACKOFF = [60, 300];

    /**
     * How many times the queue tries this notice before it gives up, `billing.notifications.tries`.
     *
     * A mail server that turns a message away for a passing reason, a timeout or a temporary refusal, has not
     * taken it, so another try does not deliver it twice. The queue sends each channel as a job of its own, so a
     * retry repeats only the channel that failed. A property rather than a method, because the job that sends the
     * notice copies it when the notice is queued and asks no method for it.
     */
    public int $tries {
        get {
            $tries = $this->setting('billing.notifications.tries');

            return is_int($tries) && $tries >= 1 ? $tries : self::TRIES;
        }
    }

    /**
     * The seconds the queue waits before each retry of this notice, `billing.notifications.backoff`: the first
     * entry before the second try, the next before the third, and the last one before every try after it. A list
     * with anything but positive whole numbers in it reads as the shipped one.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $configured = $this->setting('billing.notifications.backoff');
        $seconds = [];

        foreach (is_array($configured) ? $configured : [] as $wait) {
            if (! is_int($wait) || $wait < 1) {
                return self::BACKOFF;
            }

            $seconds[] = $wait;
        }

        return $seconds === [] ? self::BACKOFF : $seconds;
    }

    /**
     * The channels this notice goes out on — `['mail']` unless the app configures otherwise. An
     * unusable / empty configuration falls back to mail rather than sending nothing: a billing notice
     * silently going nowhere is worse than one arriving on the default channel.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->setting('billing.notifications.channels');

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
     * The button of a notice to a billing owner: the screen where its reader acts on the notice, or null for none.
     *
     * The bound `BillingActionUrls` answers, asked with the model the notice goes to. A recipient that is not a
     * model, such as an address the notice was routed to on demand, has no owner to ask about and is sent to the
     * hub's screen. A blank answer counts as none, so it cannot render a button that leads nowhere.
     */
    protected function actionFor(object $notifiable, BillingAction $action): ?string
    {
        if (! $notifiable instanceof Model) {
            return $this->actionUrl($action->route());
        }

        $url = Container::getInstance()->make(BillingActionUrls::class)->for($notifiable, $action);

        return $url === null || trim($url) === '' ? null : $url;
    }

    /**
     * A mail line plus a call to action, where one can be built.
     *
     * Written once here because every notice with a call to action needs the same two-branch shape, and a
     * copy in each of them is a place each where the guard above can be forgotten — which is the failure that
     * produces a dead link in a transactional mail rather than a missing one.
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

    /** A value of the application's config, read when it is asked for rather than when the notice was built. */
    private function setting(string $key): mixed
    {
        return Container::getInstance()->make(Repository::class)->get($key);
    }
}
