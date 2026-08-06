<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's credit balance in one currency (minor units; may be spent down toward zero).
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $currency
 * @property int $balance_minor
 */
final class CreditBalance extends Model
{
    protected $table = 'billing_credit_balances';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'currency', 'balance_minor'];

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
        'balance_minor' => 0,
    ];

    /** @var array<string,string> */
    protected $casts = ['balance_minor' => 'integer'];
}
