<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Models\Concerns\AppendOnly;
use Pushery\Billing\Support\OwnerScopedTables;
use Pushery\Billing\ValueObjects\Money;

/**
 * One movement of an owner's credit balance: what changed, by how much, and why.
 *
 * Append-only, for the same reason the audit ledger is. A balance that can be explained by rows somebody
 * may quietly rewrite is not explained at all — it merely looks it, which is worse than an unexplained
 * balance because it invites trust. So the model refuses every update and every delete, without exception.
 *
 * Deliberately WITHOUT the {@see BillingEvent::purging()} escape hatch, and the difference is worth stating
 * because the two models otherwise look alike. An audit row leaves through the model, so its guard needs a
 * sanctioned way past itself. These entries leave with the owner instead: the table is listed in
 * {@see OwnerScopedTables::PURGED} and the eraser removes it through the shared
 * owner-scoped machinery, which works on the query builder and never raises a model event. An escape hatch
 * here would therefore have had no caller at all — a mechanism whose only proof of life is its own test,
 * which is the shape this package keeps finding and removing rather than adding.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property int $amount_minor
 * @property string $currency
 * @property CreditReason $reason
 * @property ?string $source_type
 * @property ?int $source_id
 * @property ?Carbon $created_at
 */
final class CreditLedgerEntry extends Model
{
    use AppendOnly;

    protected $table = 'billing_credit_ledger_entries';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'amount_minor', 'currency', 'reason',
        'source_type', 'source_id', 'created_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'reason' => CreditReason::class,
        'created_at' => UtcDateTime::class,
    ];

    /** The movement as money, so a reader never handles a bare integer beside a bare currency string. */
    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    /** @return MorphTo<Model,$this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model,$this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Never, and that is stricter than the sibling ledgers on purpose. There is no retention window that
     * ages a credit entry out: the balance is the sum of these rows, so a row removed on a schedule would
     * silently change a balance somebody is still spending. Owner erasure takes them through the
     * owner-scoped table machinery, which does not raise model events and so never reaches this rule.
     */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A credit ledger entry is append-only and cannot be updated. The balance is the SUM of these '
            .'rows, so editing one restates money that was already spent against it.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A credit ledger entry is append-only; owner erasure removes it through the owner-scoped '
            .'table machinery, and nothing else does.';
    }
}
