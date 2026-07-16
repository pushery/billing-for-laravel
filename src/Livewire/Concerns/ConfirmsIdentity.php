<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pushery\Billing\Livewire\AccountScreen;

/**
 * Re-confirm the ACTING user's identity before an irreversible billing action (immediate cancel, the
 * account-deletion hook). Two auth models are handled without the package assuming either: a password
 * account verifies the submitted secret with {@see Hash::check}; a passwordless account (OAuth /
 * magic-link — no password column populated) has nothing to hash-check, so it verifies the submitted
 * value against the account email instead.
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
                'credential' => trans('billing::account.reconfirm.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! $this->identityMatches($actor, $credential)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'credential' => trans('billing::account.reconfirm.wrong'),
            ]);
        }

        RateLimiter::clear($key);
    }

    private function identityMatches(Model $actor, string $credential): bool
    {
        // A populated password column → the secret is a password; hash-check it.
        $hash = $actor->getAttribute('password');

        if (is_string($hash) && $hash !== '') {
            return Hash::check($credential, $hash);
        }

        // Passwordless (OAuth / magic-link): match the account email, trimmed + case-insensitive, with a
        // constant-time compare so a mismatch reveals nothing through timing.
        $email = $actor->getAttribute('email');

        return is_string($email) && $email !== ''
            && hash_equals(mb_strtolower($email), mb_strtolower(trim($credential)));
    }
}
