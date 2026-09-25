<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Carbon\CarbonImmutable;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\PaymentMethod;
use Mollie\Api\Types\TerminalStatus;
use Pushery\Billing\Contracts\CardPresentPayments;
use Pushery\Billing\Contracts\IssuesReaderPairingCodes;
use Pushery\Billing\Enums\InPersonSaleStatus;
use Pushery\Billing\Exceptions\ReaderUnavailable;
use Pushery\Billing\Models\InPersonSaleRecord;
use Pushery\Billing\Tax\SaleTaxDecision;
use Pushery\Billing\Tax\SaleTaxFacts;
use Pushery\Billing\ValueObjects\InPersonCollection;
use Pushery\Billing\ValueObjects\InPersonSale;
use Pushery\Billing\ValueObjects\ReaderPairingCode;
use Pushery\Billing\ValueObjects\TaxContext;
use Pushery\Billing\ValueObjects\TerminalReader;
use stdClass;
use Throwable;

/**
 * Card payments in person, on Mollie's point-of-sale terminals.
 *
 * A payment is created for the `pointofsale` method with the terminal named, and Mollie sends it to the terminal,
 * which takes the card. Mollie reports three things differently from Stripe, and each one changes what the path can
 * promise:
 *
 * - A terminal carries no address. The country every sale on it is placed in comes from the host
 *   (`billing.mollie.terminal_countries`), by the terminal's id or its profile's, and never from a guess such as
 *   the terminal's time zone. A terminal with no country declared takes no sale.
 * - A terminal's status says whether it is activated, not whether Mollie can reach it. An activated terminal that
 *   lost its connection takes the sale up and fails it within thirty seconds, and the webhook reports that.
 * - Mollie reports no payment in progress on a terminal. The package knows the sales it put up itself, and asks
 *   Mollie whether the last of those on the terminal is still open.
 *
 * The sale and the tax decided for it are kept in the package's own table, and the metadata only marks the
 * payment as a counter sale, so its confirmation is routed to that row.
 */
final readonly class MollieCardPresentPayments implements CardPresentPayments, IssuesReaderPairingCodes
{
    /** The metadata key that marks a payment as a sale at the counter. The confirmation reads it. */
    public const string SALE_MARKER = 'in_person_sale';

    private const string PROVIDER = 'mollie';

    /**
     * @param  array<array-key, mixed>  $terminalCountries  a terminal or profile id, and the country it stands in
     */
    public function __construct(
        private MollieApiClient $client,
        private SaleTaxDecision $tax,
        private string $webhookUrl,
        private array $terminalCountries,
    ) {}

    public function reader(string $reader): TerminalReader
    {
        $terminal = $this->client->terminals->get($reader);
        $profile = $this->text($terminal->profileId);

        return new TerminalReader(
            id: $reader,
            label: $this->text($terminal->description),
            location: $profile,
            country: $this->countryOf($reader, $profile),
            online: $terminal->status === TerminalStatus::ACTIVE,
            busy: $this->openPaymentOn($reader) instanceof Payment,
            deviceType: implode(' ', array_filter(
                [$this->text($terminal->brand), $this->text($terminal->model)],
                static fn (?string $part): bool => $part !== null,
            )),
        );
    }

    public function collect(string $reader, InPersonSale $sale): InPersonCollection
    {
        $terminal = $this->reader($reader);

        if (! $terminal->online) {
            throw ReaderUnavailable::inactive($reader);
        }

        if ($terminal->busy) {
            throw ReaderUnavailable::busy($reader);
        }

        if ($terminal->country === null) {
            throw ReaderUnavailable::withoutDeclaredCountry($reader);
        }

        $soldAt = $terminal->country;

        // Decided before the payment exists, so a sale the tax model refuses never reaches the terminal. An anonymous
        // buyer is placed at the counter: the place of goods handed over there does not depend on who buys them.
        $tax = $this->tax->decideOnGross(
            $sale->sold,
            $sale->gross,
            $sale->buyer ?? new TaxContext(countryCode: $soldAt),
            soldAlongside: $sale->soldAlongside,
            soldAt: $soldAt,
        );

        // Creating the payment is what sends it to the terminal. A retried sale with the same reference reaches the
        // same payment through the idempotency key, which is set right before the call because the client clears it
        // after every request.
        $this->client->setIdempotencyKey($sale->reference === null ? null : 'in-person-sale:'.$sale->reference);

        try {
            $payment = $this->client->payments->create([
                'description' => $sale->description,
                'amount' => ['currency' => $sale->gross->currency, 'value' => $sale->gross->toDecimal()],
                'method' => PaymentMethod::POINT_OF_SALE,
                'terminalId' => $reader,
                'webhookUrl' => $this->webhookUrl,
                'metadata' => array_filter([
                    self::SALE_MARKER => '1',
                    'sold_at' => $soldAt,
                    'reference' => $sale->reference,
                ], static fn (?string $value): bool => $value !== null),
            ]);
        } finally {
            $this->client->resetIdempotencyKey();
        }

        $this->record($payment, $reader, $soldAt, $sale, $tax);

        return new InPersonCollection(
            paymentReference: (string) $payment->id,
            reader: $reader,
            soldAt: $soldAt,
            gross: $sale->gross,
            tax: $tax,
        );
    }

    public function cancel(string $reader): void
    {
        $payment = $this->openPaymentOn($reader);

        if ($payment instanceof Payment && $payment->isCancelable === true) {
            $this->client->payments->cancel((string) $payment->id);
        }
    }

    public function requestPairingCode(string $profile): ReaderPairingCode
    {
        $code = $this->client->terminalPairingCodes->request($profile, includeQrCode: true);
        $expiresAt = $this->text($code->expiresAt);

        return new ReaderPairingCode(
            id: (string) $code->id,
            code: (string) $code->code,
            profile: $this->text($code->profileId) ?? $profile,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::parse($expiresAt),
            qrCode: $this->text($this->qrCodeOf($code->details)),
        );
    }

    /**
     * A text field of a Mollie resource, or null where it is missing or blank.
     *
     * A resource is decoded JSON, whatever its docblocks promise, so a field Mollie left out arrives as null.
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** The image a pairing code carries under `details.qrCode.src`, where Mollie sent one. */
    private function qrCodeOf(mixed $details): mixed
    {
        $qrCode = $details instanceof stdClass ? $details->qrCode ?? null : null;

        return $qrCode instanceof stdClass ? $qrCode->src ?? null : null;
    }

    /**
     * Keeps the sale and the tax decided for it, under the payment Mollie created.
     *
     * The payment is on the terminal by now, because creating it is what put it there. A sale the package could not
     * keep would be paid without a receipt, so it is taken off the terminal again and the failure is rethrown: the
     * buyer is never asked to pay for it. The rethrow sits in `finally`, so a cancellation that fails as well does
     * not hide the failure that mattered.
     */
    private function record(Payment $payment, string $reader, string $soldAt, InPersonSale $sale, SaleTaxFacts $tax): void
    {
        try {
            InPersonSaleRecord::model()::query()->firstOrCreate(
                ['provider' => self::PROVIDER, 'payment_reference' => (string) $payment->id],
                [
                    'reader' => $reader,
                    'sold_at' => $soldAt,
                    'description' => $sale->description,
                    'tax_archetype' => $sale->sold,
                    'sold_alongside_archetype' => $sale->soldAlongside,
                    'currency' => $sale->gross->currency,
                    'gross_minor' => $sale->gross->minorUnits,
                    'tax_minor' => $tax->tax->minorUnits,
                    'tax_rate_bps' => $tax->rateBps,
                    'tax_rate_category' => $tax->rateCategory,
                    'place_of_supply_rule' => $tax->placeRule(),
                    'tax_exemption_reason' => $tax->exemption,
                    'reference' => $sale->reference,
                    'status' => InPersonSaleStatus::Pending,
                ],
            );
        } catch (Throwable $failure) {
            try {
                $this->client->payments->cancel((string) $payment->id);
            } finally {
                throw $failure;
            }
        }
    }

    /**
     * The payment of the last sale the package put on this terminal, while it is still open.
     *
     * Mollie does not say whether a terminal is taking a payment, so the package answers from its own sales: the last
     * one still pending here, asked back from Mollie. A payment that is over frees the terminal even while its webhook
     * has not arrived yet, and a sale somebody else put on the terminal is not seen at all.
     */
    private function openPaymentOn(string $terminal): ?Payment
    {
        $sale = InPersonSaleRecord::model()::query()
            ->where('provider', self::PROVIDER)
            ->where('reader', $terminal)
            ->where('status', InPersonSaleStatus::Pending)
            ->latest('id')
            ->first();

        if (! $sale instanceof InPersonSaleRecord) {
            return null;
        }

        $payment = $this->client->payments->get($sale->payment_reference);

        return $payment->isPaid() || $payment->isFailed() || $payment->isCanceled() || $payment->isExpired() ? null : $payment;
    }

    /**
     * The country the host declared for a terminal, by its own id or else by its profile's.
     *
     * A terminal's own entry decides even when it is unusable, so a mistyped entry refuses the sale rather than
     * letting the profile place it in another country.
     */
    private function countryOf(string $terminal, ?string $profile): ?string
    {
        $declared = array_key_exists($terminal, $this->terminalCountries)
            ? $this->terminalCountries[$terminal]
            : ($profile === null ? null : $this->terminalCountries[$profile] ?? null);

        return is_string($declared) && preg_match('/^[A-Za-z]{2}$/', $declared) === 1 ? strtoupper($declared) : null;
    }
}
