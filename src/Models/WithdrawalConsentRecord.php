<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * A stored {@see WithdrawalConsent}: the two declarations a buyer made before a work was provided.
 *
 * The value object is what the gate reads and what the rest of the package passes around; this is only its
 * durable form. Keeping them apart is deliberate — the gate should be answerable from a consent a caller
 * built by hand, which is what lets a consumer with its own checkout supply one without adopting this table.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $reference
 * @property bool $consented_to_immediate_provision
 * @property bool $acknowledged_forfeiture
 * @property string $notice_version
 * @property ?string $immediate_provision_notice
 * @property ?string $forfeiture_notice
 * @property Carbon $given_at
 * @property ?Carbon $owner_erased_at
 */
class WithdrawalConsentRecord extends Model
{
    protected $table = 'billing_withdrawal_consents';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'reference',
        'consented_to_immediate_provision', 'acknowledged_forfeiture',
        'notice_version', 'immediate_provision_notice', 'forfeiture_notice', 'given_at', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'consented_to_immediate_provision' => 'boolean',
        'acknowledged_forfeiture' => 'boolean',
        'given_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /** The value object the gate actually reads. */
    public function toConsent(): WithdrawalConsent
    {
        return new WithdrawalConsent(
            consentedToImmediateProvision: $this->consented_to_immediate_provision,
            acknowledgedForfeiture: $this->acknowledged_forfeiture,
            noticeVersion: $this->notice_version,
            givenAt: CarbonImmutable::parse($this->given_at),
            immediateProvisionNotice: $this->immediate_provision_notice,
            forfeitureNotice: $this->forfeiture_notice,
        );
    }
}
