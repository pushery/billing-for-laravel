<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Who the reminder before the end of a trial goes to, and in which language.
 *
 * Unbound, the package's own notifier sends the reminder to the billing owner, in the owner's own locale where the owner
 * prefers one. An application whose owner is an account with several people behind it, or whose language belongs to an
 * order rather than to a model, binds an implementation, and the reminder goes to the recipients it names, in the
 * language it names. The day the reminder counts its window by is the owner's zone, as `OwnerTimezone` answers it.
 *
 * Asked once per reminder, with the owner whose trial ends.
 */
interface AddressesTrialReminders
{
    /**
     * The notifiables the reminder goes to. An empty answer sends it to nobody, and the reminder still counts as sent,
     * so the next run does not ask again for the same end.
     *
     * @return iterable<object>
     */
    public function recipients(Model $owner): iterable;

    /** The locale the reminder is written in, or null for each recipient's own and otherwise the application's. */
    public function locale(Model $owner): ?string;
}
