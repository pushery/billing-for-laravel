<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Config;

/**
 * How this package types its references to the HOST's models.
 *
 * ## The problem this exists for
 *
 * `Blueprint::morphs()` declares the id column as `bigint`. That is right for an application whose models
 * use auto-incrementing keys and wrong for one that uses UUIDs or ULIDs — and "wrong" here does not mean
 * inefficient, it means the row cannot be written at all:
 *
 * ```
 * SQLSTATE[22P02]: invalid input syntax for type bigint: "01a0742a-4ae9-71f0-ba12-24c7ca1c8b19"
 * ```
 *
 * Every table keyed to a customer or a merchant was therefore unusable for such an application, which is
 * every table that matters. It could not be worked around from outside either: the columns come from this
 * package's own migrations.
 *
 * ## Why one setting rather than one per table
 *
 * Because it is one answer. These columns all reference the application's own models — the billable, the
 * merchant, the actor on an audit row — and an application keys those consistently. A per-table setting
 * would offer a freedom nobody wants and produce installations where half the schema cannot join to the
 * other half.
 *
 * References to this package's OWN models stay `bigint` and are not routed through here — the credit
 * ledger's `source` names an order or an add-on purchase, both of which this package keys itself.
 *
 * ## Changing it later is a data migration, not a setting change
 *
 * This is read when a table is CREATED. Flipping it on an installation whose tables already exist changes
 * nothing about those tables, and the mismatch surfaces at the next insert rather than at boot. `billing:doctor`
 * reports it, which is the cheapest place to find out.
 */
final class BillingSchema
{
    /** What the host keys its own models with. `int` is the framework default and this package's. */
    public static function hostKeyType(): string
    {
        $configured = Config::get('billing.schema.host_key_type', 'int');

        return in_array($configured, ['int', 'uuid', 'ulid'], true) ? $configured : 'int';
    }

    /**
     * The length the type half is capped at when the id half stops being an integer.
     *
     * MySQL caps an index key at 3072 bytes and utf8mb4 counts four bytes per character, so a default-length
     * `varchar(255)` costs 1020 bytes of that budget. Several tables here put a morph pair inside a COMPOSITE
     * unique index, and with a `bigint` id those indexes fit — `billing_usage_counters` fits with FOUR BYTES
     * to spare (1020 + 8 + 1020 + 1020 = 3068). Widening the id to a 36-character UUID pushes it to 3204 and
     * MySQL refuses to create the index, so the whole migration fails.
     *
     * 191 characters is far more than a class name or a morph alias needs and brings the same index to 2948.
     * It is applied ONLY on the uuid/ulid path: the integer path stays byte-identical to `Blueprint::morphs()`,
     * so no existing installation's schema changes shape because this helper appeared.
     *
     * Found by the MySQL mirror. SQLite and PostgreSQL both create the index without complaint.
     */
    private const int TYPE_LENGTH = 191;

    /** A required reference to one of the host's models. */
    public static function morphs(Blueprint $table, string $name, ?string $indexName = null): void
    {
        if (self::hostKeyType() === 'int') {
            $table->morphs($name, $indexName);

            return;
        }

        $table->string($name.'_type', self::TYPE_LENGTH);
        self::hostKey($table, $name.'_id');
        $table->index([$name.'_type', $name.'_id'], $indexName);
    }

    /** An optional reference to one of the host's models. */
    public static function nullableMorphs(Blueprint $table, string $name, ?string $indexName = null): void
    {
        if (self::hostKeyType() === 'int') {
            $table->nullableMorphs($name, $indexName);

            return;
        }

        $table->string($name.'_type', self::TYPE_LENGTH)->nullable();
        self::hostKey($table, $name.'_id')->nullable();
        $table->index([$name.'_type', $name.'_id'], $indexName);
    }

    /**
     * The id half alone, for a table that declares its morph columns by hand.
     *
     * `billing_access_grants` does that because its unique index needs explicit string LENGTHS — four
     * default-length strings overrun MySQL's 3072-byte index key. It still has to follow the host's key
     * type, so the id column comes from here rather than being spelled `unsignedBigInteger` beside it.
     *
     * The definition is RETURNED so a caller can chain, which the erasure migration needs: it makes an
     * existing owner column nullable with `->nullable()->change()`. Spelled `unsignedBigInteger` there, that
     * one line would have cast a uuid column back to bigint — a migration that UNDOES the setting, on a
     * table that already holds data. That is not hypothetical; it is what this method was written after.
     */
    /**
     * The TYPE half of a host reference, at the same width the pair was created with.
     *
     * Its own method for one reason, and it is the reason the invoices index broke: a hand-written
     * `string('owner_type')->nullable()->change()` re-declares the column at the DEFAULT 255, undoing the
     * narrowing above. The migration that does it is about erasure and mentions nothing else, so nothing
     * about the line looks wrong — and the failure lands three migrations later, as MySQL refusing to build
     * an unrelated unique index.
     */
    public static function hostType(Blueprint $table, string $column): ColumnDefinition
    {
        return self::hostKeyType() === 'int'
            ? $table->string($column)
            : $table->string($column, self::TYPE_LENGTH);
    }

    public static function hostKey(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::hostKeyType()) {
            // 36 and 26 are the exact rendered lengths of a UUID and a ULID. Char, not string, because the
            // value is fixed-width and an index over it is smaller — which is the whole reason the table
            // that uses this declares its columns by hand.
            'uuid' => $table->char($column, 36),
            'ulid' => $table->char($column, 26),
            default => $table->unsignedBigInteger($column),
        };
    }
}
