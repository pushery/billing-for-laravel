<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends the reminder that a free trial is about to end. A seam, like the dunning and mandate notifiers, so a
 * host can route the mail through its own notification stack without the package knowing how.
 */
interface TrialNotifier
{
    public function trialEnding(Model $owner, DateTimeInterface $endsAt): void;
}
