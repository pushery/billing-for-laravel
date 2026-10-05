<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
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
 * ledger's `source` names an order or an add-on purchase, both of which this package keys itself, and it is
 * declared with `nullableNumericMorphs()`, because a plain `nullableMorphs()` follows the framework's default
 * into a uuid column under `Schema::morphUsingUuids()`.
 *
 * ## Not set, it is the framework's answer
 *
 * An application keyed by UUIDs or ULIDs says so to the framework with `Schema::morphUsingUuids()` or
 * `morphUsingUlids()`, and every morph the framework declares follows that. Without a setting of its own this
 * package follows the same answer. It used to read `int` instead, while the morph columns it declared through
 * the framework followed the switch: a fresh installation got UUID morph columns beside integer host keys, in
 * some tables side by side, and on MySQL and PostgreSQL the migrations stopped part way. A setting that is given
 * wins, and on the integer path every morph is declared numeric, so the columns agree with the keys beside them
 * whatever the framework's default says.
 *
 * ## Changing it later is a data migration, not a setting change
 *
 * This is read when a table is CREATED. Flipping it on an installation whose tables already exist changes
 * nothing about those tables, and the mismatch surfaces at the next insert rather than at boot. `billing:doctor`
 * reports it, which is the cheapest place to find out.
 */
final class BillingSchema
{
    /** The key types the setting can name, spelled as the framework spells them. */
    private const array HOST_KEY_TYPES = ['int', 'uuid', 'ulid'];

    /** What the host keys its own models with: the setting, or the framework's morph key type when it is not given. */
    public static function hostKeyType(): string
    {
        $configured = Config::get('billing.schema.host_key_type');

        if ($configured === null || $configured === '') {
            $configured = Builder::$defaultMorphKeyType;
        }

        return in_array($configured, self::HOST_KEY_TYPES, true) ? $configured : 'int';
    }

    /**
     * The setting as written, when it names none of the key types; null when it names one or is not given.
     *
     * {@see hostKeyType()} reads such a value as `int` rather than refusing it, because it is read while a table
     * is created and an exception there leaves a half-migrated schema. So a value like `UUID` or `guid` builds
     * integer columns without a word, and this is how `billing:doctor` names it instead.
     */
    public static function unrecognizedHostKeyType(): ?string
    {
        $configured = Config::get('billing.schema.host_key_type');

        if ($configured === null || $configured === '' || in_array($configured, self::HOST_KEY_TYPES, true)) {
            return null;
        }

        return is_scalar($configured) ? (string) $configured : get_debug_type($configured);
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
     * It is applied ONLY on the uuid/ulid path: the integer path stays byte-identical to
     * `Blueprint::numericMorphs()`, so no existing installation's schema changes shape because this helper appeared.
     *
     * Found by the MySQL mirror. SQLite and PostgreSQL both create the index without complaint.
     */
    private const int TYPE_LENGTH = 191;

    /**
     * A required reference to one of the host's models.
     *
     * `$indexed: false` leaves out the index on the pair, for a table whose own composite index leads with it.
     * That index answers every lookup by the pair, and a second one on the pair alone costs a write on every
     * insert and update and serves no query.
     */
    public static function morphs(Blueprint $table, string $name, ?string $indexName = null, bool $indexed = true): void
    {
        if (self::hostKeyType() === 'int' && $indexed) {
            $table->numericMorphs($name, $indexName);

            return;
        }

        self::hostType($table, $name.'_type');
        self::hostKey($table, $name.'_id');

        if ($indexed) {
            $table->index([$name.'_type', $name.'_id'], $indexName);
        }
    }

    /** An optional reference to one of the host's models, with the same choice about its index. */
    public static function nullableMorphs(Blueprint $table, string $name, ?string $indexName = null, bool $indexed = true): void
    {
        if (self::hostKeyType() === 'int' && $indexed) {
            $table->nullableNumericMorphs($name, $indexName);

            return;
        }

        self::hostType($table, $name.'_type')->nullable();
        self::hostKey($table, $name.'_id')->nullable();

        if ($indexed) {
            $table->index([$name.'_type', $name.'_id'], $indexName);
        }
    }

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
    public static function hostKey(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::hostKeyType()) {
            // The type Laravel's own uuidMorphs() and a host's `$table->uuid('id')` declare: the native uuid
            // type on PostgreSQL, char(36) on MySQL. PostgreSQL compares a uuid with no character column, so a
            // host table joined to one of these columns, which is what whereHas() compiles to, needs the same
            // type on both sides. 26 is the exact rendered length of a ULID, as Laravel's ulidMorphs() has it.
            'uuid' => $table->uuid($column),
            'ulid' => $table->char($column, 26),
            default => $table->unsignedBigInteger($column),
        };
    }
}
