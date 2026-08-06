<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\ToastLevel;
use Pushery\Billing\Events\AccountToastNotified;

/**
 * Raises an account toast at the owner, in the owner's own language.
 *
 * The language is the whole reason this exists. A notification gets the recipient's locale for free —
 * Laravel renders a notifiable that implements `HasLocalePreference` in its stored locale, which is why
 * every billing notice arrives correctly translated without a line of code about it. A BROADCAST gets
 * nothing of the kind: it is dispatched from a webhook or a scheduled sweep, where there is no request,
 * no session and therefore no locale but the application default. The payload it carries is a finished
 * string, because the bridge that receives it hands the text straight to a toast without a translation
 * step. So the translation has to happen HERE, at the moment of dispatch, against the recipient rather
 * than against the ambient locale — otherwise a German customer is told in English that their payment
 * failed, on an install whose default is English, and nothing anywhere looks wrong.
 *
 * The event's payload shape is deliberately left alone. Carrying a translation KEY instead of a sentence
 * would be the tidier design and it is not available: `AccountToastNotified` is published API, its
 * `{message, level}` payload is what the shipped bridge reads, and a consumer may already listen for it.
 */
final readonly class OwnerToast
{
    /**
     * @param  string  $key  a `billing::account.toast.*` translation key
     * @param  array<string, string>  $replace
     */
    public function notify(Model $owner, string $key, ToastLevel $level, array $replace = []): void
    {
        $line = $this->line($key, $owner, $replace);

        // A key that resolves to nothing raises NO toast. The alternative is broadcasting an empty
        // message, which the bridge rejects anyway — so the choice is only between failing here and
        // failing one layer further out, and failing here keeps the reason next to the cause. Throwing
        // is not an option: this runs inside the webhook effect's claim transaction, so a missing
        // translation would roll the claim back and have the provider redeliver the same event forever.
        if ($line === '') {
            return;
        }

        Event::dispatch(new AccountToastNotified($owner, $line, $level));
    }

    /**
     * The line in the OWNER's language, falling back to the ambient locale for an owner that expresses no
     * preference — which is every owner on a single-language install, so the fallback is the common path
     * and not an error case.
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, Model $owner, array $replace): string
    {
        $locale = $owner instanceof HasLocalePreference ? $owner->preferredLocale() : null;

        $line = Lang::get($key, $replace, $locale);

        // Lang::get hands back the key itself when nothing resolves. Passing that on would broadcast
        // `billing::account.toast.payment_failed` into a toast, where it would read as a system fault to
        // the customer rather than as the missing translation it is.
        return is_string($line) && $line !== $key ? $line : '';
    }
}
