<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The columns on an owner that carry a copy of the method it is charged with: `pm_type` and `pm_last_four`, the names
 * Cashier's customer migration gives them and `billing:install` adds where they are missing.
 *
 * They are optional. An owner whose table lacks them gets no copy, and nothing writes to them there.
 */
final class CardOnFileColumns
{
    /**
     * Whether the owner's table carries both columns, asked of the owner's own connection about the table a query on it
     * finds, which on PostgreSQL may sit in another schema of the `search_path` than the first.
     */
    public static function presentOn(Model $owner): bool
    {
        return $owner->getConnection()->getSchemaBuilder()->hasColumns(
            TableLookup::qualified($owner->getTable(), $owner->getConnectionName()),
            ['pm_type', 'pm_last_four'],
        );
    }

    /**
     * The two columns for a method: a card's brand or else the method's type, and its last four digits. Both are
     * null for no method at all.
     *
     * @return array{pm_type: string|null, pm_last_four: string|null}
     */
    public static function of(?PaymentMethod $method): array
    {
        return ['pm_type' => $method instanceof PaymentMethod ? $method->brand ?? $method->type : null, 'pm_last_four' => $method?->last4];
    }
}
