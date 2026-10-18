<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use Pushery\Billing\Enums\CancellationKind;
use Pushery\Billing\Enums\CancellationTiming;
use Pushery\Billing\Enums\DeclarationStatus;
use Pushery\Billing\Models\Concerns\AppendOnly;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * A cancellation as it was declared through the cancellation button.
 *
 * The row keeps what the person entered, as they entered it, and `declared_at` is the receipt: the moment the
 * declaration arrived, stamped before anything was sent. What was declared never changes afterwards. What the
 * business did about it does: the status, the end once it is known, why it waits, and when a person resolved it.
 *
 * It is addressed by its random `token`, never by its id, so a receipt page binds it by the token the confirmation
 * mail carries. An erasure unlinks it from its owner and keeps it, because a received cancellation is a business
 * letter the business has to keep. A declaration made without a signed-in owner is written already unlinked, with
 * `owner_erased_at` set to the moment it arrived, so retention counts it on the same clock.
 *
 * @property int $id
 * @property string $token
 * @property ?string $owner_type
 * @property int|string|null $owner_id
 * @property ?int $subscription_id
 * @property CancellationKind $kind
 * @property ?string $reason
 * @property string $name
 * @property string $email
 * @property string $contract
 * @property CancellationTiming $ends
 * @property ?Carbon $ends_on
 * @property string $confirmation_email
 * @property string $locale
 * @property Carbon $declared_at
 * @property ?Carbon $ends_at
 * @property DeclarationStatus $status
 * @property ?string $failure
 * @property ?string $charge_reference
 * @property ?Carbon $resolved_at
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CancellationDeclaration extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_cancellation_declarations';

    /** @var list<string> */
    protected $fillable = [
        'token', 'owner_type', 'owner_id', 'subscription_id', 'kind', 'reason', 'name', 'email', 'contract',
        'ends', 'ends_on', 'confirmation_email', 'locale', 'declared_at', 'ends_at', 'status', 'failure',
        'charge_reference', 'resolved_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'kind' => CancellationKind::class,
        'ends' => CancellationTiming::class,
        'status' => DeclarationStatus::class,
        'ends_on' => 'date',
        'declared_at' => UtcDateTime::class,
        'ends_at' => UtcDateTime::class,
        'resolved_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    #[Override]
    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * The declarations a person still has to deal with, the oldest first.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', DeclarationStatus::AwaitingReview->value)->orderBy('declared_at');
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(static function (self $declaration): void {
            if ($declaration->getAttribute('token') === null) {
                $declaration->setAttribute('token', Str::lower(Str::random(40)));
            }
        });
    }

    /**
     * What was declared stays as it arrived; what the business did about it moves, and so does an erasure.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return [
            'status', 'ends_at', 'failure', 'charge_reference', 'resolved_at', 'subscription_id',
            'owner_type', 'owner_id', 'owner_erased_at', 'updated_at',
        ];
    }

    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A cancellation declaration keeps what the person declared, and that cannot be updated: '.implode(', ', $columns).'. '
            .'Its status, its end and its resolution can.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A cancellation declaration is a business letter the business received, and a caller does not delete it. An '
            .'erasure unlinks it from its owner, and retention removes it once the window of an erased owner\'s records has passed.';
    }
}
