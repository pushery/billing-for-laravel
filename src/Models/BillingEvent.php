<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\Concerns\AppendOnly;

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
    use AppendOnly;

    protected $table = 'billing_events';

    /** @var list<string> */
    protected $fillable = ['type', 'source', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'payload'];

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
        'source' => 'system',
    ];

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

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A billing audit event is append-only and cannot be updated.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A billing audit event can only be deleted by retention pruning or owner erasure.';
    }
}
