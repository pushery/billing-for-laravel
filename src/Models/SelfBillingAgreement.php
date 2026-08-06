<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * One accepted (and possibly later revoked) self-billing agreement with a creator.
 *
 * A creator may hold several of these over time — each clause version appends a new row rather than editing
 * the last — so "does an agreement authorize this supply" is a question a moment answers, not a boolean on
 * the creator. {@see authorizes()} is that question.
 *
 * ## Who writes this row
 *
 * The CONSUMING APPLICATION, at onboarding: accepting a self-billing clause happens in their flow, in their
 * words, with their record of how consent was captured (`evidence`). The package only ever asks whether an
 * agreement authorizes a given supply (`SelfBillingAgreementGuard`) and refuses the settlement when none
 * does. Columns with no writer in `src/` are therefore expected here.
 *
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property ?Carbon $merchant_erased_at
 * @property Carbon $accepted_at
 * @property string $terms_version
 * @property ?array<string, mixed> $evidence
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 */
final class SelfBillingAgreement extends Model
{
    protected $table = 'billing_self_billing_agreements';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'merchant_erased_at', 'accepted_at', 'terms_version', 'evidence', 'revoked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        // Both instants are compared against a supply date, so both are kept in UTC on read and write —
        // the default cast reads back in app.timezone and would move a boundary across a midnight supply.
        'accepted_at' => UtcDateTime::class,
        'revoked_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
        'evidence' => 'array',
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this agreement authorizes a self-billed document for a supply on the given date.
     *
     * Strict ex ante on both ends: it must have been accepted at or before the supply, and not yet revoked
     * as of the supply — a revocation dated after the supply leaves that supply covered, because the
     * arrangement was live when the supply happened.
     */
    public function authorizes(CarbonInterface $supplyDate): bool
    {
        if ($this->accepted_at->greaterThan($supplyDate)) {
            return false;
        }

        return ! $this->revoked_at instanceof CarbonInterface || $this->revoked_at->greaterThan($supplyDate);
    }
}
