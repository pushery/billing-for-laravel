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
 * The proof that an account holder gave a SEPA direct debit mandate on the application's own form.
 *
 * It keeps the mandate as it was confirmed: the text the account holder read, the reference, the creditor
 * identifier, the account holder's name, the country and the last four characters of the account, the notice
 * period agreed beside the mandate, and when and from where the confirmation came. The row is written once.
 * An erasure unlinks it from its owner and keeps it, because the payee has to be able to show the mandate for
 * as long as a debit drawn under it can be disputed.
 *
 * @property int $id
 * @property ?string $owner_type
 * @property int|string|null $owner_id
 * @property string $provider
 * @property string $mandate_reference
 * @property string $reference
 * @property string $account_holder
 * @property string $account_country
 * @property string $account_last_four
 * @property ?string $notify_email
 * @property string $creditor_identifier
 * @property string $language
 * @property string $statement
 * @property int $notice_days
 * @property Carbon $signed_on
 * @property Carbon $consented_at
 * @property ?string $consent_ip
 * @property ?string $consent_user_agent
 * @property ?Carbon $owner_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MandateConsent extends Model
{
    use AppendOnly;
    use Replaceable;

    protected $table = 'billing_mandate_consents';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'mandate_reference', 'reference',
        'account_holder', 'account_country', 'account_last_four', 'notify_email',
        'creditor_identifier', 'language', 'statement', 'notice_days',
        'signed_on', 'consented_at', 'consent_ip', 'consent_user_agent', 'owner_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'notice_days' => 'integer',
        'signed_on' => 'date',
        'consented_at' => UtcDateTime::class,
        'owner_erased_at' => UtcDateTime::class,
    ];

    /** The consent behind a stored mandate, or null for a mandate that was not given on a form of the application. */
    public static function forMandate(string $provider, string $mandateReference): ?self
    {
        return static::model()::query()
            ->where('provider', $provider)
            ->where('mandate_reference', $mandateReference)
            ->first();
    }

    /**
     * An erasure unlinks the person from the consent; what was confirmed does not move.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return ['owner_type', 'owner_id', 'owner_erased_at'];
    }

    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::Never;
    }

    #[Override]
    protected static function appendOnlyUpdateRefusal(array $columns): string
    {
        return 'A mandate consent records what an account holder confirmed, and cannot be updated: '.implode(', ', $columns).'. '
            .'A changed mandate is a new mandate with a consent of its own.';
    }

    #[Override]
    protected static function appendOnlyDeleteRefusal(): string
    {
        return 'A mandate consent is the proof of a direct debit mandate and is not deleted by a caller. An erasure unlinks '
            .'it from its owner, and retention removes it once the window of an erased owner\'s financial records has passed.';
    }
}
