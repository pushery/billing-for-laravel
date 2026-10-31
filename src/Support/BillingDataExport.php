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

            $export[$table] = array_map(static function (array $row) use ($zones): array {
                foreach ($zones as $column => $zone) {
                    $value = $row[$column] ?? null;

                    if (is_string($value)) {
                        $row[$column] = CarbonImmutable::parse($value, $zone)->toIso8601String();
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
