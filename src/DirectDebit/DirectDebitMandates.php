<?php

declare(strict_types=1);

namespace Pushery\Billing\DirectDebit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CollectsDirectDebits;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Contracts\SepaMandateWording;
use Pushery\Billing\Exceptions\DirectDebitMandateRefused;
use Pushery\Billing\Models\MandateConsent;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Support\UniqueRow;
use Pushery\Billing\ValueObjects\DirectDebitConsent;
use Pushery\Billing\ValueObjects\Iban;
use Pushery\Billing\ValueObjects\SepaMandateText;

/**
 * SEPA direct debit mandates an account holder gives on the application's own form, without a payment.
 *
 * One call per screen. {@see self::text()} answers the mandate the form shows for the payer's country, under a new
 * reference. {@see self::give()} registers what the account holder confirmed: it checks the IBAN and that the text is
 * still the one the form showed, registers the mandate at the provider, keeps the consent, and makes the mandate the
 * one the local engine charges, because the form is where the account holder chose it.
 *
 * A driver offers this by implementing {@see CollectsDirectDebits}; the Mollie driver does. On any other driver
 * `text()` answers null and `give()` refuses.
 */
final readonly class DirectDebitMandates
{
    /** The characters a SEPA mandate reference may hold, at most 35 of them. */
    private const string REFERENCE_PATTERN = '/^[A-Za-z0-9+?\/:().,\' -]{1,35}$/';

    public function __construct(
        private Container $container,
        private Repository $config,
        private PreNotifications $notices,
    ) {}

    /**
     * The mandate a form shows for a payer in this country, or null where the package takes none there.
     *
     * Null on a driver that does not register such mandates, for a country without a wording in
     * `billing.direct_debit.mandate_wordings`, and while `billing.company.name` is empty, because the mandate names
     * the business the payee collects for. A form that gets null offers another way to pay.
     *
     * @param  ?string  $reference  the mandate reference to name; a new one when null
     *
     * @throws DirectDebitMandateRefused for a reference that is not one a bank takes
     */
    public function text(string $country, ?string $reference = null): ?SepaMandateText
    {
        $rails = $this->rails();
        $wording = $this->wordingFor($country);
        $payee = $this->payee();

        if (! $rails instanceof CollectsDirectDebits || ! $wording instanceof SepaMandateWording || $payee === null) {
            return null;
        }

        return $wording->text($rails->creditor(), $payee, $this->checkedReference($reference ?? $this->newReference()), $this->notices->configuredDays());
    }

    /**
     * Register the mandate an account holder confirmed, keep the consent, and answer the stored mandate.
     *
     * @throws DirectDebitMandateRefused when the driver takes no such mandates, the IBAN or the account holder is not
     *                                   usable, there is no wording or payee, the text changed since the form showed
     *                                   it, or the provider did not accept the mandate
     */
    public function give(Model $owner, DirectDebitConsent $consent): PaymentMandate
    {
        $rails = $this->rails() ?? throw DirectDebitMandateRefused::driverCannot($this->driver());
        $iban = Iban::tryFrom($consent->iban) ?? throw DirectDebitMandateRefused::invalidIban();
        $holder = trim($consent->accountHolder);

        if ($holder === '') {
            throw DirectDebitMandateRefused::noAccountHolder();
        }

        $country = strtoupper(trim($consent->country));
        $wording = $this->wordingFor($country) ?? throw DirectDebitMandateRefused::noWording($country);
        $payee = $this->payee() ?? throw DirectDebitMandateRefused::noPayee();
        $text = $wording->text($rails->creditor(), $payee, $this->checkedReference($consent->reference), $this->notices->configuredDays());

        if ($text->statement() !== $consent->statement) {
            throw DirectDebitMandateRefused::statementChanged();
        }

        $signedAt = Carbon::now()->utc();
        $customer = $this->container->make(EnsuresProviderCustomer::class)->customerFor($owner);
        $reference = $rails->giveDirectDebitMandate($customer, $holder, $iban, $text->reference, $signedAt);
        $provider = $this->driver();

        return DB::transaction(function () use ($owner, $consent, $iban, $holder, $text, $rails, $signedAt, $reference, $provider): PaymentMandate {
            $mandate = UniqueRow::firstOrCreate(
                PaymentMandate::model()::query(),
                ['provider' => $provider, 'mandate_reference' => $reference->id],
                [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'customer_reference' => $reference->customerReference,
                    'method' => $reference->method,
                    'status' => PaymentMandate::CHARGEABLE,
                ],
            );

            $mandate->makeDefault();

            MandateConsent::model()::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'provider' => $provider,
                'mandate_reference' => $reference->id,
                'reference' => $text->reference,
                'account_holder' => $holder,
                'account_country' => $iban->country(),
                'account_last_four' => $iban->lastFour(),
                'notify_email' => $consent->notifyEmail !== null && trim($consent->notifyEmail) !== '' ? trim($consent->notifyEmail) : null,
                'creditor_identifier' => $rails->creditor()->identifier,
                'language' => $text->language,
                'statement' => $text->statement(),
                'notice_days' => $text->noticeDays,
                'signed_on' => $signedAt->toDateString(),
                'consented_at' => $signedAt,
                'consent_ip' => $consent->ip,
                'consent_user_agent' => $consent->userAgent,
            ]);

            return $mandate;
        });
    }

    /** The active driver's rails, where they register direct debit mandates. */
    private function rails(): ?CollectsDirectDebits
    {
        if (! $this->container->bound(CollectsDirectDebits::class)) {
            return null;
        }

        return $this->container->make(CollectsDirectDebits::class);
    }

    /** The wording configured for payers in this country, or null where there is none to use. */
    private function wordingFor(string $country): ?SepaMandateWording
    {
        $wordings = $this->config->get('billing.direct_debit.mandate_wordings');
        $class = is_array($wordings) ? ($wordings[strtoupper(trim($country))] ?? null) : null;

        if (! is_string($class) || ! is_subclass_of($class, SepaMandateWording::class)) {
            return null;
        }

        $wording = $this->container->make($class);

        return $wording instanceof SepaMandateWording ? $wording : null;
    }

    /** The business the mandate names as the one the payee collects for. */
    private function payee(): ?string
    {
        $name = $this->config->get('billing.company.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function driver(): string
    {
        $driver = $this->config->get('billing.default');

        return is_string($driver) && $driver !== '' ? $driver : 'unknown';
    }

    /** A new reference: the letters `MD` and 24 random hexadecimal digits, 27 characters, unique without asking anybody. */
    private function newReference(): string
    {
        return 'MD-'.strtoupper(bin2hex(random_bytes(12)));
    }

    /** @throws DirectDebitMandateRefused for a reference a bank does not take */
    private function checkedReference(string $reference): string
    {
        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            throw DirectDebitMandateRefused::invalidReference();
        }

        return $reference;
    }
}
