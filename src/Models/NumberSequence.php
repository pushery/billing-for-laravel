<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The next value of a gap-free number sequence, one row per scope.
 *
 * @property string $scope
 * @property int $next_number
 */
final class NumberSequence extends Model
{
    protected $table = 'billing_number_sequences';

    /** @var list<string> */
    protected $fillable = ['scope', 'next_number'];

    /** @var array<string,string> */
    protected $casts = ['next_number' => 'integer'];
}
