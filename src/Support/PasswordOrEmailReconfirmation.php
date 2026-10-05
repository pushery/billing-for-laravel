<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Pushery\Billing\Contracts\ReconfirmsIdentity;

/**
 * The default re-confirmation: a password account proves its password, a passwordless account its account email.
 *
 * Only the password is a secret. The email of a passwordless account is no proof against someone who holds its
 * session, which is why {@see ReconfirmsIdentity} can be bound to a factor of the application's own.
 */
final class PasswordOrEmailReconfirmation implements ReconfirmsIdentity
{
    public function prompt(Model $actor): string
    {
        $key = $this->password($actor) === null ? 'billing::account.reconfirm.prompt_email' : 'billing::account.reconfirm.prompt';
        $line = Lang::get($key);

        return is_string($line) ? $line : $key;
    }

    public function confirms(Model $actor, string $credential): bool
    {
        $hash = $this->password($actor);

        if ($hash !== null) {
            return Hash::check($credential, $hash);
        }

        // Trimmed of whitespace, a non-breaking or invisible space a paste brings along included, and compared
        // case-insensitively in constant time, so a mismatch reveals nothing through timing.
        $email = $actor->getAttribute('email');

        return is_string($email) && $email !== ''
            && hash_equals(mb_strtolower($email), mb_strtolower(Str::trim($credential)));
    }

    /** The account's password hash, or null for an account that signs in without one. */
    private function password(Model $actor): ?string
    {
        $hash = $actor->getAttribute('password');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
