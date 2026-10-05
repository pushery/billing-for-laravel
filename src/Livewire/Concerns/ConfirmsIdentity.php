<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pushery\Billing\Contracts\ReconfirmsIdentity;
use Pushery\Billing\Livewire\AccountScreen;

/**
 * Re-confirm the ACTING user's identity before an irreversible billing action (immediate cancel). What proves it
 * is the bound {@see ReconfirmsIdentity}: by default a password account proves its password and a passwordless
 * account (OAuth / magic-link) its account email, which a held session knows, so an application without
 * passwords binds a factor of its own.
 *
 * The attempt is per-user rate-limited (5 tries / 5 minutes) and the limiter is checked BEFORE the
 * credential is verified, so a brute-force is locked out rather than probed one attempt at a time. Only
 * a wrong credential consumes an attempt; a correct one clears the counter. The submitted credential is
 * NEVER persisted or logged — it lives only for the duration of the check.
 *
 * The using component must expose `actor()` (every {@see AccountScreen} does):
 * the identity re-confirmed is the signed-in user who clicked, not the billing owner (which may be a team).
 */
trait ConfirmsIdentity
{
    abstract protected function actor(): Model;

    /**
     * Verify the submitted credential belongs to the acting user, or throw a validation error on the
     * `credential` field. Throttled per user; never stores the credential.
     */
    protected function confirmIdentity(string $credential): void
    {
        $actor = $this->actor();

        // getKey() is mixed and may be an int or a string (UUID) key — narrow to a scalar so the throttle
        // key is stable and unique per user (an int-cast would collapse every string key to 0 → one shared
        // bucket, defeating the per-user throttle).
        $id = $actor->getKey();
        $key = 'billing:reconfirm:'.$actor->getMorphClass().':'.(is_scalar($id) ? (string) $id : '');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'credential' => Lang::get('billing::account.reconfirm.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! $this->reconfirmation()->confirms($actor, $credential)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'credential' => Lang::get('billing::account.reconfirm.wrong'),
            ]);
        }

        RateLimiter::clear($key);
    }

    /** The line that asks the acting user for what {@see confirmIdentity()} checks. */
    protected function reconfirmationPrompt(): string
    {
        return $this->reconfirmation()->prompt($this->actor());
    }

    private function reconfirmation(): ReconfirmsIdentity
    {
        return Container::getInstance()->make(ReconfirmsIdentity::class);
    }
}
