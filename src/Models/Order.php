<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\ValueObjects\Money;

/**
 * A local order: the billing unit a driver without a provider-side order model assembles for a due cycle,
 * processes, and produces an invoice from.
 *
 * The total is authoritative on the row (summed from the items when the order is assembled), so reading it
 * never touches the item rows or a provider. Dates are UTC instants, like every other provider timestamp in
 * the schema.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $provider
 * @property ?int $subscription_id
 * @property int $total_minor
 * @property string $currency
 * @property OrderStatus $status
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property ?Carbon $processed_at
 * @property ?string $payment_reference
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at when this row last moved — how a charge that has been in flight too long is spotted
 */
final class Order extends Model
{
    /**
     * How long a claim may sit unanswered before it counts as abandoned.
     *
     * Six hours, and the number is not chosen here — it is the boundary `billing:doctor` has always
     * reported a stranded claim at. Defining it once is the point: a resume that fired before the
     * diagnostic reported it would act on cycles an operator was never told about, and one that fired
     * later would leave a report standing with nothing able to act on it. Two numbers that must agree
     * and are written down twice are two numbers that will disagree.
     */
    public const int ABANDONED_CLAIM_HOURS = 6;

    protected $table = 'billing_orders';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'subscription_id', 'total_minor', 'currency',
        'status', 'period_start', 'period_end', 'processed_at', 'payment_reference',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'subscription_id' => 'integer',
        'total_minor' => 'integer',
        'status' => OrderStatus::class,
        'period_start' => UtcDateTime::class,
        'period_end' => UtcDateTime::class,
        'processed_at' => UtcDateTime::class,
    ];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /** The order total as Money. */
    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /**
     * Whether this order's claim was abandoned: taken, never charged, and old enough that nothing is coming.
     *
     * Three conditions, and each one is load-bearing:
     *
     *  - `processing` — a paid cycle is finished and a failed one is already back on the dunning path;
     *  - **no payment reference** — the engine writes one the moment the provider answers, in either
     *    direction, so its absence is the closest thing there is to "the provider was never called".
     *    It is not a proof: a process that died mid-call leaves no reference and may still have created
     *    a payment. That residual is why releasing a claim is an operator's decision and not a sweep's;
     *  - **older than {@see self::ABANDONED_CLAIM_HOURS}** — a charge takes seconds to a minute, so a
     *    younger claim is almost certainly still in flight, and acting on one is how a cycle gets billed
     *    twice by a process racing itself.
     */
    public function isAbandonedClaim(?CarbonInterface $now = null): bool
    {
        return $this->status === OrderStatus::Processing
            && $this->payment_reference === null
            && $this->created_at instanceof Carbon
            && $this->created_at->lessThan($this->abandonedBefore($now));
    }

    /**
     * The same three conditions as a query, for a caller counting rows rather than holding one.
     *
     * Deliberately beside {@see self::isAbandonedClaim()} rather than derived from it — a builder and an
     * in-memory predicate cannot share an expression without a layer that would obscure both. What holds
     * them together is a test that runs them over the same rows and requires the same answer, which is a
     * stronger guarantee than shared code that is merely believed to mean the same thing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAbandonedClaims(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->where('status', OrderStatus::Processing)
            ->whereNull('payment_reference')
            ->where('created_at', '<', $this->abandonedBefore($now));
    }

    /** The instant a claim must predate to count as abandoned. */
    private function abandonedBefore(?CarbonInterface $now = null): CarbonInterface
    {
        return ($now instanceof CarbonInterface ? $now->copy() : Carbon::now())
            ->subHours(self::ABANDONED_CLAIM_HOURS);
    }
}
