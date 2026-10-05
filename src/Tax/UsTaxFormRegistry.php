<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\UsTaxFormStatus;
use Pushery\Billing\Enums\UsTaxFormType;
use Pushery\Billing\Models\UsTaxForm;

/**
 * What a seller declared about where they are taxed, and whether it still counts.
 *
 * ## Collected while the regime is off, acted on only when it is on
 *
 * That split is the whole design. Collecting is safe and has to happen early: a declaration is given at
 * onboarding or it is chased a year later from sellers who have moved or gone quiet, under a filing
 * deadline, and that chase ends in withholding money from people who did nothing wrong. ACTING on a
 * declaration — withholding, reporting — is a different matter entirely, and it stays behind the switch
 * until somebody turns the regime on deliberately.
 *
 * So this reads and records regardless, and {@see self::activeRegime()} is what any consequence has to ask
 * first. A consumer with no US exposure carries a few unused rows; one who switches the regime on finds the
 * declarations already there.
 *
 * ## Latest wins, expiry counts
 *
 * A seller can give a new declaration — they move, they incorporate — and the newest is the one that
 * describes them. An expired one is worth exactly what an absent one is: a foreign declaration goes stale on
 * a schedule, and treating a stale one as valid is the same mistake as never having asked. The same holds
 * for one that was asked for and never arrived, or arrived unusable: {@see self::currentFor()} returns it so
 * a caller can see where the seller stands, and {@see self::covered()} does not count it.
 */
final readonly class UsTaxFormRegistry
{
    public function __construct(private Repository $config) {}

    /** Whether this installation acts on these declarations at all. */
    public function activeRegime(): bool
    {
        return (bool) $this->config->get('billing.tax_us.enabled', false);
    }

    /**
     * Record what a seller declared. Allowed while the regime is off — collecting early is the point.
     *
     * Both dates are kept as the calendar day they name in their own zone, whatever their hour.
     */
    public function record(
        Model $merchant,
        UsTaxFormType $type,
        CarbonInterface $signedOn,
        ?CarbonInterface $expiresOn = null,
        ?string $documentReference = null,
        UsTaxFormStatus $status = UsTaxFormStatus::OnFile,
    ): UsTaxForm {
        return UsTaxForm::model()::query()->create([
            'merchant_type' => $merchant->getMorphClass(),
            'merchant_id' => $this->key($merchant),
            'form_type' => $type,
            'status' => $status,
            'signed_on' => $signedOn->toDateString(),
            'expires_on' => $expiresOn?->toDateString(),
            'document_reference' => $documentReference,
        ]);
    }

    /**
     * The declaration that describes this seller on the day of `$asOf`, or null where none does.
     *
     * Days are compared as days: a declaration counts from the day it was signed through the whole of the day it
     * expires on, with the day of `$asOf` read in the zone it carries, whatever its hour.
     */
    public function currentFor(Model $merchant, CarbonInterface $asOf): ?UsTaxForm
    {
        $day = $asOf->toDateString();

        $form = UsTaxForm::model()::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $this->key($merchant))
            ->whereDate('signed_on', '<=', $day)
            ->orderByDesc('signed_on')
            ->orderByDesc('id')
            ->first();

        if (! $form instanceof UsTaxForm) {
            return null;
        }

        // An expired declaration is worth what an absent one is. Returning it would let a caller act on a
        // statement the seller is no longer making.
        return $form->expires_on !== null && $form->expires_on->toDateString() < $day ? null : $form;
    }

    /**
     * Whether this seller has a declaration anything may act on.
     *
     * A rejected or still-outstanding one is not one: both mean the seller has not told us what we asked,
     * and treating "we asked" as an answer is how a platform ends up acting on a statement nobody made.
     */
    public function covered(Model $merchant, CarbonInterface $asOf): bool
    {
        return $this->currentFor($merchant, $asOf)?->status->usable() ?? false;
    }

    private function key(Model $merchant): string
    {
        $key = $merchant->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
