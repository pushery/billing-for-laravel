<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * One change in a market's standing.
 *
 * @property int $id
 * @property string $country
 * @property MarketAccess $state
 * @property ?string $actor
 * @property Carbon $recorded_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class MarketAccessEntry extends Model
{
    use AppendOnly;

    protected $table = 'billing_market_access_log';

    /** @var list<string> */
    protected $fillable = ['country', 'state', 'actor', 'recorded_at'];

    /** @var array<string,string> */
    protected $casts = [
        'state' => MarketAccess::class,
        'recorded_at' => UtcDateTime::class,
    ];

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A market-access entry records a change that happened and cannot be edited. Closing a '
            .'market that was open writes a second entry — the sales made while it was open are real '
            .'and need the record that explains them.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'This row carries a statutory retention window; retention removes it on its schedule, '
            .'inside purging(). A caller does not delete it.';
    }
}
