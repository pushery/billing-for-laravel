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
use Pushery\Billing\Enums\DeclarationStatus;
use Pushery\Billing\Models\Concerns\AppendOnly;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * A withdrawal as it was declared through the withdrawal function.
 *
 * The row keeps what the person entered, as they entered it, and `declared_at` is the receipt: a withdrawal is in
 * time when it was sent through the function before the withdrawal period ran out, so the moment it was sent is the
 * fact that decides it. What was declared never changes afterwards; what the business did about it does.
 *
 * It is addressed by its random `token`, never by its id. An erasure unlinks it from its owner and keeps it, because
 * a received withdrawal is a business letter the business has to keep. A declaration made without a signed-in owner
 * is written already unlinked, with `owner_erased_at` set to the moment it arrived, so retention counts it on the
 * same clock.
 *
 * @property int $id
 * @property string $token
 * @property ?string $owner_type
 * @property int|string|null $owner_id
 * @property ?int $subscription_id
 * @property string $name
 * @property string $contract
 * @property string $confirmation_email
 * @property string $locale
 * @property Carbon $declared_at
 * @property DeclarationStatus $status
 * @property ?string $failure
 * @property ?string $charge_reference
 * @property ?Carbon $resolved_at
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WithdrawalDeclaration extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_withdrawal_declarations';

    /** @var list<string> */
    protected $fillable = [
        'token', 'owner_type', 'owner_id', 'subscription_id', 'name', 'contract', 'confirmation_email', 'locale',
        'declared_at', 'status', 'failure', 'charge_reference', 'resolved_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'status' => DeclarationStatus::class,
        'declared_at' => UtcDateTime::class,
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
            'status', 'failure', 'charge_reference', 'resolved_at', 'subscription_id',
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
        return 'A withdrawal declaration keeps what the person sent through the withdrawal function, and that cannot be updated: '
            .implode(', ', $columns).'. Its status and its resolution can.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A withdrawal declaration is the proof that a withdrawal was sent in time, and a caller does not delete it. An '
            .'erasure unlinks it from its owner, and retention removes it once the window of an erased owner\'s records has passed.';
    }
}
