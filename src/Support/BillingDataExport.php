<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\Webhooks\WebhookPayloadSecrets;

/**
 * Everything the package holds about one person, as plain data — the answer to a subject-access or
 * data-portability request (GDPR Art. 15 and Art. 20). That is the person as a buyer and, on a marketplace, as a
 * merchant: the same account can do both, and its merchant rows carry no owner columns.
 *
 * It reads the same table map the eraser does, so the two cannot drift: a table the export forgets is data
 * a person is entitled to and never receives, and every table added to the package is covered by both or by
 * neither.
 *
 * The webhook deliveries are included with their raw payloads. That is deliberate and slightly
 * uncomfortable: they are the person's data, they are what the package actually stores, and an export that
 * quietly leaves out the biggest file is not an honest one.
 */
final readonly class BillingDataExport
{
    /**
     * The integer columns that keep a moment as Unix seconds, by table.
     *
     * A provider's event time is kept as the seconds the provider reports, so that two of its events compare exactly.
     * The schema cannot tell such a column from one that holds a count, so the package names them here, and a test
     * holds the list to every integer column of the schema whose name ends in `_at`.
     */
    private const array UNIX_SECOND_COLUMNS = [
        'billing_merchant_accounts' => ['capabilities_event_at'],
        'billing_subscriptions' => ['synced_event_at'],
    ];

    /**
     * The declarations anybody can make without signing in, by table, with the column holding the address the person
     * gave as their own.
     *
     * The cancellation button asks for the person's address and, beside it, where the confirmation goes; the withdrawal
     * function asks only for the address its confirmation goes to, so on a withdrawal that address is the person's.
     */
    private const array DECLARATION_ADDRESSES = [
        'billing_cancellation_declarations' => 'email',
        'billing_withdrawal_declarations' => 'confirmation_email',
    ];

    /**
     * The shared table machinery is defaulted rather than required: this class is public surface a consumer
     * may legitimately construct with `new`, and it is stateless, so demanding the dependency would break
     * that for no benefit. The container still injects its own instance.
     *
     * The manager, which decides the mode, is defaulted for the same reason: one constructed with `new` asks the
     * container for it when it exports.
     */
    public function __construct(
        private SubjectScopedRecords $records = new SubjectScopedRecords,
        private ?BillingManager $manager = null,
    ) {}

    /**
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function for(Model $owner): array
    {
        // Payments-only mode keeps the webhook deliveries and nothing else (PaymentsModeSchema), so what it holds
        // about a person is the deliveries recorded for them, payloads included.
        if (($this->manager ?? Container::getInstance()->make(BillingManager::class))->paymentsOnly()) {
            return $this->withMoments($this->withoutDeliverySecrets($this->records->export(PaymentsModeSchema::ownerAxis(), $owner)));
        }

        // The same axis, the same table work the eraser does — read out instead of deleted. Child tables
        // included: they key on their parent row rather than on the person, so they are reached by joining
        // through the parent, and covering them here but not there (or the reverse) is exactly the drift
        // one shared implementation makes impossible.
        $export = $this->records->export(OwnerScopedTables::ownerAxis(), $owner);

        // The same person as a merchant, on the other side of a routed sale: their provider account, payouts,
        // balances, tax standing and the documents about them. A table both sides list holds rows that belong to
        // a buyer, such as the subscription a fan holds with the merchant. The buyer receives those in their own
        // export, and handing them to the merchant would hand over somebody else's data (Art. 15(4)).
        $buyerTables = OwnerScopedTables::ownerAxis()->all();

        foreach ($this->records->export(OwnerScopedTables::merchantAxis(), $owner) as $table => $rows) {
            if (! in_array($table, $buyerTables, true)) {
                $export[$table] = $rows;
            }
        }

        // The coupons the person issued as a merchant. They are scoped by the sentinel string rather than by morph
        // columns, so no axis reaches them, and they are theirs all the same.
        $export['billing_coupons'] = $this->couponsIssuedBy($owner);

        // The access the person bought for somebody else. It is keyed to the recipient, so no axis reaches the person
        // who paid, and the purchase is theirs all the same.
        $export['billing_access_grants'] = [...$export['billing_access_grants'] ?? [], ...$this->giftsBoughtBy($owner)];

        // A cancellation or a withdrawal declared without signing in is written unlinked, so no axis reaches it, and the
        // person who declared it is entitled to it all the same. So is the holder of the contract a declaration names.
        foreach (self::DECLARATION_ADDRESSES as $table => $addressColumn) {
            $export[$table] = [...$export[$table] ?? [], ...$this->declarationsReachedOtherwise($owner, $table, $addressColumn, $export[$table] ?? [])];
        }

        // The audit ledger keys on subject/actor, not owner — but a subject-access request covers the
        // owner's billing history all the same, so include the rows where they are the subject or the actor.
        $export['billing_events'] = array_values(DB::table('billing_events')
            ->where(fn (Builder $q): Builder => $q
                ->where('subject_type', $owner->getMorphClass())->where('subject_id', $owner->getKey()))
            ->orWhere(fn (Builder $q): Builder => $q
                ->where('actor_type', $owner->getMorphClass())->where('actor_id', $owner->getKey()))
            ->get()
            ->map(fn (object $row): array => $this->isAbout($row, $owner) ? (array) $row : $this->withoutTheOtherParty((array) $row))
            ->all());

        return $this->withMoments($this->withoutDeliverySecrets($export));
    }

    /**
     * The export with every moment in ISO 8601 with its offset, and a date as a date.
     *
     * A moment kept as Unix seconds, in a column {@see self::UNIX_SECOND_COLUMNS} names, is written in UTC.
     *
     * A row leaves the database as the database writes it, `2026-10-26 21:00:00`, without a `T` and without a zone,
     * and the person reading their copy cannot place it. Which columns hold a moment comes from the schema rather
     * than from their names: `period_start` and `decide_by` are moments as much as `created_at` is. Each is read in
     * the zone it was written in, as the package's models read it: UTC for a column a model casts with
     * UtcDateTime, the application's time zone for every other.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $export
     * @return array<string, list<array<array-key, mixed>>>
     */
    private function withMoments(array $export): array
    {
        $utc = $this->utcColumns();

        foreach ($export as $table => $rows) {
            $zones = [];

            foreach (Schema::getColumns(TableLookup::qualified($table)) as $column) {
                $name = is_array($column) ? ($column['name'] ?? null) : null;
                $type = is_array($column) ? ($column['type_name'] ?? null) : null;

                if (is_string($name) && in_array($type, ['timestamp', 'timestamptz', 'datetime'], true)) {
                    $zones[$name] = in_array($name, $utc[$table] ?? [], true) ? 'UTC' : null;
                }
            }

            $seconds = self::UNIX_SECOND_COLUMNS[$table] ?? [];

            $export[$table] = array_map(static function (array $row) use ($zones, $seconds): array {
                foreach ($zones as $column => $zone) {
                    $value = $row[$column] ?? null;

                    if (is_string($value)) {
                        $row[$column] = CarbonImmutable::parse($value, $zone)->toIso8601String();
                    }
                }

                foreach ($seconds as $column) {
                    $value = $row[$column] ?? null;

                    if (is_numeric($value)) {
                        $row[$column] = CarbonImmutable::createFromTimestampUTC((int) $value)->toIso8601String();
                    }
                }

                return $row;
            }, $rows);
        }

        return $export;
    }

    /**
     * The columns each of the package's models casts with UtcDateTime, by table.
     *
     * @return array<string, list<string>>
     */
    private function utcColumns(): array
    {
        $columns = [];

        foreach (glob(__DIR__.'/../Models/*.php') ?: [] as $file) {
            $class = 'Pushery\\Billing\\Models\\'.basename($file, '.php');
            $model = new $class;

            if ($model instanceof Model) {
                foreach ($model->getCasts() as $column => $cast) {
                    if ($cast === UtcDateTime::class) {
                        $columns[$model->getTable()][] = $column;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * The export with the fields a provider asks not to be kept removed from every delivery payload in it.
     *
     * The ledger strips them before it stores a delivery. A payload stored before it did keeps them until the retention
     * clock prunes it, and the person asking for their data is handed the payload as it stands, so the export strips
     * them as well: the payment details stay in the copy, and the secret that could confirm a payment does not.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $export
     * @return array<string, list<array<array-key, mixed>>>
     */
    private function withoutDeliverySecrets(array $export): array
    {
        if (isset($export['billing_webhook_events'])) {
            $export['billing_webhook_events'] = array_map($this->withoutPayloadSecrets(...), $export['billing_webhook_events']);
        }

        return $export;
    }

    /**
     * One delivery row with its payload stripped.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private function withoutPayloadSecrets(array $row): array
    {
        if (array_key_exists('payload', $row)) {
            $row['payload'] = WebhookPayloadSecrets::stripJson($row['payload']);
        }

        return $row;
    }

    /** Whether an audit row is about the person, rather than one in which they only acted. */
    private function isAbout(object $row, Model $owner): bool
    {
        $key = $owner->getKey();
        $subjectId = $row->subject_id ?? null;

        return ($row->subject_type ?? null) === $owner->getMorphClass()
            && is_scalar($subjectId) && is_scalar($key) && (string) $subjectId === (string) $key;
    }

    /**
     * A row in which the person only acted, without the party it was about.
     *
     * What the person did is theirs: the type, the source and the moment. Whom it was done to and what the row
     * records about them belong to that other party, a customer a support agent refunded, and a copy handed to the
     * agent must not hand those over (Art. 15(4)).
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private function withoutTheOtherParty(array $row): array
    {
        return array_replace($row, ['subject_type' => null, 'subject_id' => null, 'payload' => null]);
    }

    /**
     * The grants this person bought for somebody else, without the recipient.
     *
     * What was bought, from whom, when and under which declaration is the purchaser's. Who received it is the
     * recipient's, so the copy leaves out the two columns that name them (Art. 15(4)), as it leaves out the other
     * party of an audit row. A grant the person bought and holds themselves is in the export already, on the buyer's
     * axis, and is not repeated here. None when the model is not saved: a filter on no key would read as `IS NULL`
     * and match every grant nobody bought.
     *
     * @return list<array<array-key, mixed>>
     */
    private function giftsBoughtBy(Model $owner): array
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return [];
        }

        return array_values(DB::table('billing_access_grants')
            ->where('purchaser_type', $owner->getMorphClass())
            ->where('purchaser_id', $key)
            ->whereNot(fn (Builder $q): Builder => $q
                ->where('owner_type', $owner->getMorphClass())->where('owner_id', $key))
            ->get()
            ->map(fn (object $row): array => array_replace((array) $row, ['owner_type' => null, 'owner_id' => null]))
            ->all());
    }

    /**
     * The declarations of one table that are the person's without naming them as their owner, each once.
     *
     * Two ways lead to such a row. One was declared without signing in under the address the account answers to, its
     * `email`, compared without regard to case because the declaration keeps the address as it was typed. Only an
     * unlinked row is read that way: one another account was signed in to make is that account's declaration, and
     * handing it over by an address typed into it would hand over somebody else's data (Art. 15(4)). The other names
     * one of the subscriptions the account holds, as a declaration does when a signed-in person picked one of the
     * contracts listed to them, and the contract is the holder's.
     *
     * The eraser reaches neither, and it does not have to: each row is a business letter kept as proof, and one written
     * without a signed-in owner is on the retention clock from the moment it arrived. None when the model is not saved.
     *
     * @param  'email'|'confirmation_email'  $addressColumn
     * @param  list<array<array-key, mixed>>  $found  the rows of the table the owner axis found already
     * @return list<array<array-key, mixed>>
     */
    private function declarationsReachedOtherwise(Model $owner, string $table, string $addressColumn, array $found): array
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return [];
        }

        $axis = OwnerScopedTables::ownerAxis();
        $email = $owner->getAttribute('email');
        $address = is_string($email) ? mb_strtolower(trim($email)) : '';

        $subscriptions = DB::table('billing_subscriptions')
            ->where($axis->typeColumn, $owner->getMorphClass())
            ->where($axis->idColumn, $key)
            ->select('id');

        return array_values(DB::table($table)
            ->whereNotIn('id', array_column($found, 'id'))
            ->where(fn (Builder $q): Builder => $q
                ->whereIn('subscription_id', $subscriptions)
                ->when($address !== '', fn (Builder $q): Builder => $q->orWhere(fn (Builder $q): Builder => $q
                    ->whereNull($axis->typeColumn)
                    ->whereRaw('lower('.$addressColumn.') = ?', [$address]))))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());
    }

    /**
     * The coupons this person issued as a merchant, none when the model is not saved and so scopes nothing.
     *
     * @return list<array<array-key, mixed>>
     */
    private function couponsIssuedBy(Model $owner): array
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return [];
        }

        return array_values(DB::table('billing_coupons')
            ->where('merchant_uid', MerchantScope::forMerchant($owner)->uid())
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());
    }
}
