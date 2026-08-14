<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Enums\DocumentDeliveryEvent;
use Pushery\Billing\Models\Concerns\AppendOnly;

/**
 * One thing that happened to a settlement document on its way to its recipient.
 *
 * Append-only, and enforced rather than intended: a delivery log whose rows can be edited proves nothing,
 * because the version produced in a dispute would be the version written after the dispute started. An
 * update or a delete throws.
 *
 * @property int $id
 * @property string $document_number
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property DocumentDeliveryEvent $event
 * @property ?string $channel
 * @property ?string $recipient
 * @property ?string $outcome
 * @property ?string $detail
 * @property Carbon $occurred_at
 * @property ?Carbon $merchant_erased_at
 */
class DocumentDelivery extends Model
{
    use AppendOnly;

    protected $table = 'billing_document_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'document_number', 'merchant_type', 'merchant_id', 'event',
        'channel', 'recipient', 'outcome', 'detail', 'occurred_at', 'merchant_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'event' => DocumentDeliveryEvent::class,
        'occurred_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Erasure is the one exception, and it is not an edit of what happened: it unlinks the person from a
     * record whose CONTENT is untouched, which is exactly what an unlinkable evidentiary log is for.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['merchant_type', 'merchant_id', 'merchant_erased_at'];
    }

    /** Never, by any path: this row is unlinked from an erased person rather than removed. */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A delivery log entry records what happened at a moment and cannot be changed afterwards; '
            .'attempted to change '.implode(', ', $columns).'. Record a new event instead — a log whose rows '
            .'can be edited proves nothing, because the version produced in a dispute would be the version '
            .'written after the dispute began.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A delivery log entry is the evidence that a document was delivered and shares its retention '
            .'period; it is unlinked from an erased person, never deleted.';
    }
}
