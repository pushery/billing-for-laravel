<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Models\Concerns\AppendOnly;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * The bytes of an electronic document, as issued.
 *
 * @property int $id
 * @property ?string $owner_type
 * @property ?string $owner_id
 * @property string $document_number
 * @property string $syntax
 * @property Carbon $issued_at
 * @property string $checksum
 * @property string $contents
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class DocumentArtifact extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_document_artifacts';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'document_number', 'syntax',
        'issued_at', 'checksum', 'contents', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'issued_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /**
     * Erasure unlinks the person from a record whose CONTENT is untouched. Everything else would be
     * re-deciding after the fact what was already decided and acted on.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['owner_type', 'owner_id', 'owner_erased_at', 'updated_at'];
    }

    /**
     * Never through the model, inside `purging()` or not. The erasure axis holds this table as RETAINED:
     * unlinked when its owner is erased, and removed by query once the retention window has passed.
     */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A stored document is the artifact that left this system and cannot be edited; attempted '
            .'to change '.implode(', ', $columns).'. What the recipient holds did not change, so '
            .'neither does this.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A stored document is not deleted by a caller. An erasure unlinks it from its owner, and '
            .'retention removes it once the window of an erased owner\'s documents has passed.';
    }
}
