<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\GrantSource;
use Pushery\Billing\Enums\GrantStatus;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Enums\UpdatePolicy;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * One person's ownership of one work.
 *
 * Ownership is a FACT, not a state: it outlives the plan that was current when it was bought, the creator's
 * account, and the work's own publication. That is why this is a row of its own rather than something read
 * from a subscription — and why nothing here is soft-deleted. A grant that stopped granting says so, with a
 * reason and a date, because "why can this person no longer read what they bought" is a question somebody
 * will ask and a deleted row cannot answer.
 *
 * ## Who writes this row
 *
 * The PACKAGE does, through `ContentGrants::grant()`. Some of its columns nonetheless arrive from the
 * consuming application rather than being derived here -- `version_pin_ref` is the clearest: which version a
 * buyer was pinned to is a fact about their catalog. That is why an absent pin is answered fail-closed
 * (bounded at the moment of purchase) rather than as "the newest", which would be the opposite of what was
 * sold.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property ?string $purchaser_type
 * @property ?int $purchaser_id
 * @property string $content_type
 * @property string $content_ref
 * @property GrantSource $source
 * @property GrantStatus $status
 * @property Carbon $acquired_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $revoked_at
 * @property ?RevokeReason $revoked_reason
 * @property ?string $source_reference
 * @property UpdatePolicy $update_policy
 * @property ?string $version_pin_ref
 * @property ?Carbon $update_window_ends_at
 * @property ?Carbon $conformity_update_until
 * @property ?Carbon $withdrawal_window_ends_at
 * @property bool $conformity_waiver
 * @property ?string $conformity_waiver_ref
 * @property ?WithdrawalType $withdrawal_type
 * @property ?string $withdrawal_declaration_ref
 * @property ?string $bundle_ref
 * @property ?int $max_seats
 * @property string $merchant_uid
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 */
final class AccessGrant extends Model
{
    protected $table = 'billing_access_grants';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'purchaser_type', 'purchaser_id',
        'content_type', 'content_ref', 'source', 'status', 'acquired_at', 'expires_at',
        'revoked_at', 'revoked_reason', 'source_reference',
        'update_policy', 'version_pin_ref', 'update_window_ends_at',
        'conformity_update_until', 'conformity_waiver', 'conformity_waiver_ref', 'withdrawal_window_ends_at',
        'withdrawal_type', 'withdrawal_declaration_ref',
        'bundle_ref', 'max_seats', 'merchant_uid', 'merchant_type', 'merchant_id',
    ];

    /**
     * The same defaults the schema carries. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'status' => 'active',
        'update_policy' => 'latest',
        'conformity_waiver' => false,
        'merchant_uid' => 'platform',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source' => GrantSource::class,
        'status' => GrantStatus::class,
        'revoked_reason' => RevokeReason::class,
        'update_policy' => UpdatePolicy::class,
        'withdrawal_type' => WithdrawalType::class,
        'conformity_waiver' => 'boolean',
        'max_seats' => 'integer',
        'acquired_at' => UtcDateTime::class,
        'expires_at' => UtcDateTime::class,
        'revoked_at' => UtcDateTime::class,
        'update_window_ends_at' => UtcDateTime::class,
        'conformity_update_until' => UtcDateTime::class,
        'withdrawal_window_ends_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who paid, when that is somebody other than the owner — a gift. Null otherwise.
     *
     * @return MorphTo<Model, $this>
     */
    public function purchaser(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The merchant this grant is attributed to. Attribution only: the platform is the seller.
     *
     * @return MorphTo<Model, $this>
     */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to one merchant, or to the platform when none is given.
     *
     * Keyed on the sentinel rather than the morph, for the reason the migration gives: a nullable column
     * inside the uniqueness makes the guard vanish on MySQL. Reading through the same key the index uses
     * keeps the query and the constraint talking about the same thing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForMerchant(Builder $query, ?MerchantScope $merchant = null): void
    {
        $query->where('merchant_uid', ($merchant ?? MerchantScope::platform())->uid());
    }

    /**
     * Whether this grant grants at a given moment.
     *
     * Deliberately NOT just `status === Active`. A row can be active and out of its window — a rental whose
     * end passed and whose sweep has not run yet — and answering from the status alone would hand somebody
     * access to a work whose term ended, for as long as the sweep is late. The dates decide; the status
     * records why, not whether.
     */
    public function grantsAt(Carbon $moment): bool
    {
        if ($this->status !== GrantStatus::Active) {
            return false;
        }

        if ($this->acquired_at->greaterThan($moment)) {
            return false;
        }

        return ! $this->expires_at instanceof Carbon || $this->expires_at->greaterThan($moment);
    }
}
