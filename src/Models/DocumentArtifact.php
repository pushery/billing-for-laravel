<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use RuntimeException;

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
final class DocumentArtifact extends Model
{
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

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $artifact): void {
            // Erasure unlinks the person from an artifact whose CONTENT is untouched — the same exception the
            // delivery log makes, and for the same reason. Anything else would be editing what was issued.
            $touched = array_keys($artifact->getDirty());
            $allowed = ['owner_type', 'owner_id', 'owner_erased_at', 'updated_at'];

            if (array_diff($touched, $allowed) !== []) {
                throw new RuntimeException(
                    'A stored document is the artifact that left this system and cannot be edited; attempted '
                    .'to change '.implode(', ', array_diff($touched, $allowed)).'. What the recipient holds '
                    .'did not change, so neither does this.'
                );
            }
        });
    }
}
