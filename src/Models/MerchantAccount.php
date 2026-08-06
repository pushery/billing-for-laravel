<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\MerchantStatus;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The local record of a merchant's account at the payment provider.
 *
 * It is the package's cache of what the provider has confirmed, not an authority of its own. The three
 * capability flags are written by the provider's own events and read on the money path; nothing else may
 * set them, because a flag the platform could raise itself would let a marketplace route money to a
 * merchant the provider never verified.
 *
 * @property string $merchant_type
 * @property int $merchant_id
 * @property string $provider
 * @property string $account_reference
 * @property MerchantStatus $status
 * @property ?string $status_reason
 * @property ?Carbon $status_changed_at
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property ?Carbon $deauthorized_at
 * @property ?Carbon $capabilities_refreshed_at
 */
final class MerchantAccount extends Model
{
    protected $table = 'billing_merchant_accounts';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'provider', 'account_reference', 'status', 'status_reason', 'status_changed_at',
        'charges_enabled', 'payouts_enabled', 'details_submitted', 'deauthorized_at', 'capabilities_refreshed_at',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a freshly created instance holds null for every column the database would have filled,
     * and the difference only shows on the create-then-use path: everything that queries the row first sees
     * the schema default and looks fine. That is the whole failure — the account whose very first use is by
     * the code that just made it, which is exactly what onboarding does.
     *
     * @var array<string, string|bool>
     */
    protected $attributes = [
        'status' => 'active',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
    ];

    /** @var array<string,string> */
    protected $casts = [
        'status' => MerchantStatus::class,
        'status_changed_at' => UtcDateTime::class,
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        // Provider instants, kept in UTC on both read and write: the default cast reads back in
        // app.timezone, which would report a refresh as hours older or newer than it was.
        'capabilities_refreshed_at' => UtcDateTime::class,
        'deauthorized_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /** The row as the value object the neutral layer passes around. */
    public function toReference(): MerchantAccountReference
    {
        return new MerchantAccountReference(
            provider: $this->provider,
            accountId: $this->account_reference,
            chargesEnabled: $this->charges_enabled,
            payoutsEnabled: $this->payouts_enabled,
            detailsSubmitted: $this->details_submitted,
            deauthorizedAt: $this->deauthorized_at,
            // The platform's own position travels with the provider's flags. A caller holding only the
            // flags would route to a merchant this platform has suspended, because the provider never
            // withdrew anything — the decision was made here.
            status: $this->status,
        );
    }
}
