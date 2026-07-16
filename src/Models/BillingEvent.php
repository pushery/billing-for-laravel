<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Enums\AuditSource;
use RuntimeException;

/**
 * One row of the billing audit ledger.
 *
 * @property string $type
 * @property AuditSource $source
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?string $actor_type
 * @property ?int $actor_id
 * @property array<string,mixed> $payload
 * @property ?Carbon $created_at
 */
final class BillingEvent extends Model
{
    /**
     * Whether a deliberate purge (retention pruning, owner erasure) is in progress. An audit ledger is only
     * evidence if it cannot be quietly rewritten, so the model refuses every update and every delete EXCEPT
     * inside a call wrapped by {@see self::purging()}. That is the one authorized way rows leave — a clock or
     * a right-to-erasure request — never an ad-hoc edit.
     */
    private static bool $purging = false;

    protected $table = 'billing_events';

    /** @var list<string> */
    protected $fillable = ['type', 'source', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'payload'];

    /** @var array<string,string> */
    protected $casts = ['payload' => 'array', 'source' => AuditSource::class];

    /**
     * Run a deliberate purge (the retention prune, the owner erasure) with the append-only guard lifted, so
     * those — and only those — may delete audit rows. Restores the guard afterwards, even on an exception.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function purging(callable $callback): mixed
    {
        self::$purging = true;

        try {
            return $callback();
        } finally {
            self::$purging = false;
        }
    }

    #[Override]
    protected static function booted(): void
    {
        // An audit row is written once and never changed. An update is always a mistake; a delete is only
        // legitimate through purging() (retention / erasure). Anything else is someone rewriting history.
        self::updating(static function (): never {
            throw new RuntimeException('A billing audit event is append-only and cannot be updated.');
        });

        self::deleting(static function (): bool {
            if (! self::$purging) {
                throw new RuntimeException('A billing audit event can only be deleted by retention pruning or owner erasure.');
            }

            return true;
        });
    }

    /** @return MorphTo<Model,$this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model,$this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
