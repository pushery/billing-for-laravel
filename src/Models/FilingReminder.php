<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\FilingObligation;

/**
 * A record that one filing obligation, for one due date, has been announced.
 *
 * @property int $id
 * @property FilingObligation $obligation
 * @property Carbon $due_on
 * @property Carbon $announced_at
 */
final class FilingReminder extends Model
{
    protected $table = 'billing_filing_reminders';

    /** @var list<string> */
    protected $fillable = ['obligation', 'due_on', 'announced_at'];

    /** @var array<string,string> */
    protected $casts = [
        'obligation' => FilingObligation::class,
        'due_on' => 'date',
        'announced_at' => UtcDateTime::class,
    ];
}
