<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\ValueObjects\ErasureAxis;

/**
 * What payments-only mode keeps in the database: the webhook deliveries, and the runs of the effects an application
 * registers on them.
 *
 * A payment into a payment account leaves no record of its own in the package. The charge and the refund go to the
 * provider and come back as webhooks, so the delivery ledger is all the mode writes, and these two tables are all
 * `migrate` creates in it.
 *
 * The migrations are named here, out of the directory both modes share, rather than kept in a directory of their own.
 * A migration keeps its name in the host's `migrations` table, so an installation that switches from payments-only to
 * full mode later runs the rest of the directory in its order and skips none of it. Two of the files are payments-only
 * counterparts of a full-mode migration that also shapes other tables; each finds the column it builds already in place
 * when the full-mode one ran first.
 */
final class PaymentsModeSchema
{
    /**
     * The tables payments-only mode creates and writes.
     *
     * @var list<string>
     */
    public const array TABLES = ['billing_webhook_events', 'billing_webhook_effect_runs'];

    /**
     * The migrations that build those tables and nothing else, in the order the migrator runs them.
     *
     * @var list<string>
     */
    public const array MIGRATIONS = [
        '2026_07_12_000001_create_billing_webhook_events_table',
        '2026_07_14_000013_add_delivery_columns_to_billing_webhook_events_table',
        '2026_07_14_000014_create_billing_webhook_effect_runs_table',
        '2026_07_14_000016_add_the_owner_to_billing_webhook_events_table',
        '2026_07_24_000031_add_account_reference_to_billing_webhook_events_table',
        '2026_10_02_000138_add_payload_removed_at_to_billing_webhook_events_table',
        '2026_10_02_000139_redact_stored_errors_on_billing_webhook_effect_runs_table',
        '2026_10_04_000158_make_the_uuid_host_key_of_the_webhook_owner_native_on_postgresql',
        '2026_10_19_000181_strip_client_secrets_from_billing_webhook_events_table',
    ];

    /**
     * The files of those migrations in the directory both modes share.
     *
     * @return list<string>
     */
    public static function migrationFiles(string $directory): array
    {
        return array_map(static fn (string $name): string => $directory.'/'.$name.'.php', self::MIGRATIONS);
    }

    /**
     * The owner axis an erasure and a data export walk in payments-only mode: the stored payloads of an owner's webhook
     * deliveries, scrubbed as in full mode, and none of the tables full mode purges, keeps or cascades through.
     */
    public static function ownerAxis(): ErasureAxis
    {
        $full = OwnerScopedTables::ownerAxis();

        return new ErasureAxis(
            name: $full->name,
            typeColumn: $full->typeColumn,
            idColumn: $full->idColumn,
            erasedAtColumn: $full->erasedAtColumn,
            purged: [],
            retained: [],
            scrubbed: $full->scrubbed,
            cascaded: [],
            scrubbedAt: $full->scrubbedAt,
        );
    }
}
