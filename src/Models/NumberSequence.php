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

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'next_number' => 1,
    ];

    /** @var array<string,string> */
    protected $casts = ['next_number' => 'integer'];
}
