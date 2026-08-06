<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Enums\ReversalCause;

/**
 * One intent to reverse money, recorded before the provider hears about it.
 *
 * @property int $id
 * @property string $provider
 * @property string $charge_reference
 * @property int $amount_minor
 * @property string $currency
 * @property int $transfer_reversal_minor
 * @property int $fee_refund_minor
 * @property string $idempotency_key
 * @property RefundAttemptStatus $status
 * @property ?string $failure_reason
 * @property ?Carbon $completed_at
 * @property ?ReversalCause $cause
 * @property ?int $dispute_fee_minor
 */
final class RefundAttempt extends Model
{
    protected $table = 'billing_refund_attempts';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'charge_reference', 'amount_minor', 'currency', 'transfer_reversal_minor',
        'fee_refund_minor', 'idempotency_key', 'status', 'failure_reason', 'completed_at',
        'cause', 'dispute_fee_minor',
    ];

    /**
     * The same defaults the schema carries. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'transfer_reversal_minor' => 0,
        'fee_refund_minor' => 0,
        'status' => 'pending',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'transfer_reversal_minor' => 'integer',
        'fee_refund_minor' => 'integer',
        'status' => RefundAttemptStatus::class,
        'completed_at' => UtcDateTime::class,
        'cause' => ReversalCause::class,
        // Deliberately NOT defaulted to 0. Null means no dispute happened; zero means one did and the
        // provider charged nothing for it, which is a claim worth checking against a statement.
        'dispute_fee_minor' => 'integer',
    ];

    /**
     * The provider idempotency key for an attempt row.
     *
     * Derived from the row's own id, which is assigned by the database and never changes. That is the
     * entire point: a retry of the same intent finds the same row and therefore sends the same key, where
     * anything recomputed from mutable state would mint a second one and refund twice.
     */
    public static function keyFor(int $id): string
    {
        return 'billing_refund_'.$id;
    }
}
