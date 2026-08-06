<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's PREPAID balance for one meter — units they bought outright ("+1000 emails").
 *
 * Persistent and cycle-independent: unlike the tier's `included` allowance (which lives in the
 * period-scoped usage counter and expires with the cycle), prepaid units roll forever. Paid is paid.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $meter_key
 * @property int $balance
 * @property int $granted_total
 */
final class PrepaidUnits extends Model
{
    protected $table = 'billing_prepaid_units';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'meter_key', 'balance', 'granted_total'];

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
        'balance' => 0,
        'granted_total' => 0,
    ];

    /** @var array<string, string> */
    protected $casts = ['balance' => 'integer', 'granted_total' => 'integer'];
}
