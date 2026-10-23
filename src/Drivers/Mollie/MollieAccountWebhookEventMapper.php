<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Auth\AccessTokenAuthenticator;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\AccountPaymentStatus;
use Pushery\Billing\Events\AccountPaymentChanged;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\Money;

/**
 * Reads a payment of one payment account back from Mollie when Mollie pings the account's webhook address, and reports
 * where it stands as an AccountPaymentChanged.
 *
 * Mollie pings with the payment's id when the payment is paid, authorized, expired, failed or canceled, when one of
 * its refunds reaches processing, refunded or failed, and when a chargeback arrives. The ping carries nothing else, so
 * the payment is read back with the account's own client, which also proves it is the account's: a payment of another
 * account does not resolve with this credential.
 *
 * A live account reached through an OAuth access token can also make payments in test mode, through the same access,
 * and Mollie does not show a test payment to a request in live mode. So for such an account a payment that is unknown
 * in live mode is asked for once more in test mode, and the change reports which mode the payment ran in.
 */
final class MollieAccountWebhookEventMapper implements DerivesDeliveryKey, WebhookEventMapper
{
    /** @var array<string, ?AccountPaymentChanged> */
    private array $read = [];

    public function __construct(
        private readonly MollieApiClient $client,
        private readonly string $account,
    ) {}

    /**
     * The payment, its status and its amounts, so a refund or a chargeback is a delivery of its own: the status of a
     * paid payment does not move when money goes back.
     */
    public function deliveryKey(Request $request): ?string
    {
        $change = $this->changeOf($request);

        if (! $change instanceof AccountPaymentChanged) {
            return null;
        }

        return implode(':', [
            $change->paymentReference,
            $change->status->value,
            $change->amountRefunded->minorUnits,
            $change->amountChargedBack->minorUnits,
            $change->refundReference ?? '',
        ]);
    }

    /** @return iterable<AccountPaymentChanged> */
    public function map(Request $request): iterable
    {
        $change = $this->changeOf($request);

        if ($change instanceof AccountPaymentChanged) {
            yield $change;
        }
    }

    /** Where the pinged payment stands, read once per request, or null where the ping names no payment of the account. */
    private function changeOf(Request $request): ?AccountPaymentChanged
    {
        $id = $this->paymentIdOf($request);

        if ($id === null) {
            return null;
        }

        if (array_key_exists($id, $this->read)) {
            return $this->read[$id];
        }

        $payment = $this->fetch($id);

        return $this->read[$id] = $payment instanceof Payment ? $this->standing($payment) : null;
    }

    private function standing(Payment $payment): AccountPaymentChanged
    {
        $amount = MollieAmount::fromResource($payment->amount);
        $refunded = MollieAmount::fromOptionalResource($payment->amountRefunded, $amount->currency);
        $chargedBack = $payment->hasChargebacks()
            ? MollieChargebackEvents::standing($payment->chargebacks(), $amount->currency)
            : Money::zero($amount->currency);
        $failedRefund = $payment->isPaid() && $payment->hasRefunds() ? $this->failedNewest($payment->refunds()) : null;
        [$billingReference, $metadata] = $this->metadataOf($payment->metadata);

        return new AccountPaymentChanged(
            'mollie',
            $this->account,
            MollieValue::id($payment->id),
            match (true) {
                $payment->isPaid() => $this->paidStatus($amount, $refunded, $chargedBack, $failedRefund),
                $payment->isAuthorized() => AccountPaymentStatus::Authorized,
                $payment->isFailed() => AccountPaymentStatus::Failed,
                $payment->isCanceled() => AccountPaymentStatus::Canceled,
                $payment->isExpired() => AccountPaymentStatus::Expired,
                default => AccountPaymentStatus::Pending,
            },
            $amount,
            $refunded,
            $chargedBack,
            $billingReference,
            $metadata,
            $failedRefund,
            testmode: MollieValue::of($payment->mode) === 'test',
        );
    }

    /**
     * Where a paid payment stands. A chargeback outranks the rest, because the bank holds the money whatever happened
     * to the refunds; a failed refund outranks the amounts, because it is the newest thing that happened.
     */
    private function paidStatus(Money $amount, Money $refunded, Money $chargedBack, ?string $failedRefund): AccountPaymentStatus
    {
        return match (true) {
            $chargedBack->isPositive() => AccountPaymentStatus::ChargedBack,
            $failedRefund !== null => AccountPaymentStatus::RefundFailed,
            ! $refunded->lessThan($amount) => AccountPaymentStatus::Refunded,
            $refunded->isPositive() => AccountPaymentStatus::PartiallyRefunded,
            default => AccountPaymentStatus::Paid,
        };
    }

    /**
     * The id of the newest refund where that refund failed, or null.
     *
     * @param  iterable<mixed>  $refunds
     */
    private function failedNewest(iterable $refunds): ?string
    {
        $newest = null;

        foreach ($refunds as $refund) {
            if ($refund instanceof Refund && (! $newest instanceof Refund || strcmp($refund->createdAt, $newest->createdAt) > 0)) {
                $newest = $refund;
            }
        }

        return $newest instanceof Refund && $newest->isFailed() ? MollieValue::id($newest->id) : null;
    }

    /**
     * The idempotency key the charge carried, and the rest of its metadata as the charge's options set it.
     *
     * @return array{0: ?string, 1: array<string, string|int>}
     */
    private function metadataOf(mixed $metadata): array
    {
        $values = is_object($metadata) ? get_object_vars($metadata) : (is_array($metadata) ? $metadata : []);
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== ChargeOptions::RESERVED_METADATA_KEY && (is_string($value) || is_int($value))) {
                $kept[$key] = $value;
            }
        }

        $reference = $values[ChargeOptions::RESERVED_METADATA_KEY] ?? null;

        return [is_string($reference) && $reference !== '' ? $reference : null, $kept];
    }

    /** The payment a ping names: `entityId` in a next-generation event, `id` in the classic form ping. */
    private function paymentIdOf(Request $request): ?string
    {
        $id = $request->input('entityId') ?? ($request->input('type') === null ? $request->input('id') : null);

        return is_string($id) && str_starts_with(trim($id), 'tr_') ? trim($id) : null;
    }

    /**
     * The payment, or null where Mollie answers that it does not exist for this account, in live mode and, where the
     * account can hold them, in test mode.
     */
    private function fetch(string $id): ?Payment
    {
        return $this->fetchIn($id, testmode: false) ?? ($this->holdsTestPaymentsToo() ? $this->fetchIn($id, testmode: true) : null);
    }

    /**
     * Whether the account can hold test payments its own mode does not show: a live account reached through an OAuth
     * access token. An API key carries its mode in itself, so the SDK sends no test flag with it, and a test account
     * already reads in test mode.
     */
    private function holdsTestPaymentsToo(): bool
    {
        return $this->client->getTestmode() !== true && $this->client->getAuthenticator() instanceof AccessTokenAuthenticator;
    }

    /**
     * The payment in one mode, or null where Mollie answers that it does not exist there. Every other refusal travels,
     * so the receiver answers 500 and Mollie delivers the ping again.
     */
    private function fetchIn(string $id, bool $testmode): ?Payment
    {
        $request = new GetPaymentRequest($id);

        if ($testmode) {
            $request->test();
        }

        try {
            return MollieValue::narrow($this->client->send($request), Payment::class);
        } catch (RequestException $refusal) {
            if (! in_array($refusal->getStatusCode(), [404, 410], true)) {
                throw $refusal;
            }

            return null;
        }
    }
}
