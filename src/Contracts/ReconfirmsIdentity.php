<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * What the acting user proves before an irreversible billing action, such as ending a subscription at once from the
 * account hub's danger zone.
 *
 * The package checks what every account model carries. A password account proves its password. A passwordless
 * account, one that signs in through a provider or a link by mail, has no secret the package can read, so the
 * default asks for its account email. The email is known to whoever holds the session: for such an account the
 * default stops an accidental click, not someone who took the session over. An application that signs people in
 * without a password binds this contract to a factor of its own, a one-time code for one.
 *
 * The attempts stay throttled per acting user whatever is bound, and the typed credential is never stored.
 */
interface ReconfirmsIdentity
{
    /** The line that asks the acting user for what {@see confirms()} checks, shown above the field they type into. */
    public function prompt(Model $actor): string;

    /** Whether $credential proves that $actor is the person behind the session. */
    public function confirms(Model $actor, string $credential): bool;
}
