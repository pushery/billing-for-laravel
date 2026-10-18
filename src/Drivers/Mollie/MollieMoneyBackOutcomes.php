<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Requests\GetPaginatedChargebacksRequest;
use Mollie\Api\Http\Requests\GetPaginatedRefundsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Chargeback;
use Mollie\Api\Resources\CursorCollection;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Pushery\Billing\Contracts\ReportsMoneyBackOutcomes;
use Pushery\Billing\Enums\RefundFailure;
use Pushery\Billing\Events\ChargebackReversed;
use Pushery\Billing\Events\RefundFailed;
use Pushery\Billing\ValueObjects\Money;
use Throwable;
use UnexpectedValueException;

/**
 * What became of Mollie's refunds and chargebacks where its webhook does not say.
 *
 * Mollie calls no webhook for a refund canceled before it was paid out, and documents none for a chargeback the bank
 * reverses. The webhook of a payment is read for its refunded total, which says nothing about a refund that failed.
 * So both lists are read back, newest first, as far as their windows reach.
 *
 * A payment is read once per run, for the customer it belongs to: a payment can carry several refunds.
 */
final class MollieMoneyBackOutcomes implements ReportsMoneyBackOutcomes
{
    /** Mollie's largest page. */
    private const int PAGE_SIZE = 250;

    /**
     * How many pages of one list a run reads: 10,000 entries.
     *
     * A window that holds more ends there with a warning, and what the pages read hold is still reported. Reading on
     * without an end would hold the scheduler's lock for as long as the account has history.
     */
    private const int MAX_PAGES = 40;

    /** @var array<string, ?Payment> payment id => the payment, null where Mollie no longer knows it */
    private array $payments = [];

    public function __construct(private readonly MollieApiClient $client) {}

    public function outcomesSince(CarbonImmutable $refundsSince, CarbonImmutable $chargebacksSince): iterable
    {
        foreach ($this->listed(new GetPaginatedRefundsRequest(limit: self::PAGE_SIZE), Refund::class, $refundsSince, 'refunds') as $refund) {
            $failure = match (true) {
                $refund->isFailed() => RefundFailure::Failed,
                $refund->isCanceled() => RefundFailure::Canceled,
                default => null,
            };

            if ($failure === null) {
                continue;
            }

            $id = MollieValue::id($refund->id ?? null);
            $amount = $this->amountOf($refund->amount ?? null, 'refund', $id);

            if (! $amount instanceof Money) {
                continue;
            }

            $payment = MollieValue::id($refund->paymentId ?? null);

            yield new RefundFailed($this->customerOf($payment), $payment, $id, $amount, $failure);
        }

        foreach ($this->listed(new GetPaginatedChargebacksRequest(limit: self::PAGE_SIZE), Chargeback::class, $chargebacksSince, 'chargebacks') as $chargeback) {
            $reversedAt = $chargeback->reversedAt ?? null;

            if (! is_string($reversedAt) || $reversedAt === '') {
                continue;
            }

            $id = MollieValue::id($chargeback->id ?? null);
            $amount = $this->amountOf($chargeback->amount ?? null, 'chargeback', $id);

            if (! $amount instanceof Money) {
                continue;
            }

            $payment = MollieValue::id($chargeback->paymentId ?? null);

            yield new ChargebackReversed(
                $this->customerOf($payment),
                $payment,
                $amount,
                $id,
                $this->dateOf($reversedAt),
                $this->standingRefundedOf($payment, $amount->currency),
            );
        }
    }

    /**
     * The entries of one list, newest first, up to the first one created before `$since`.
     *
     * @template T of Chargeback|Refund
     *
     * @param  class-string<T>  $class
     * @return iterable<T>
     */
    private function listed(GetPaginatedChargebacksRequest|GetPaginatedRefundsRequest $request, string $class, CarbonImmutable $since, string $list): iterable
    {
        $page = MollieValue::narrow($this->client->send($request), CursorCollection::class);

        for ($read = 1; $page instanceof CursorCollection; $read++) {
            foreach ($page as $entry) {
                // The SDK hydrates every entry of a list as the resource of that list, so a page of refunds holds refunds.
                $resource = $entry instanceof $class ? $entry : throw new UnexpectedValueException("Mollie's list of {$list} holds an entry of another kind.");
                $created = $this->dateOf($resource->createdAt ?? null);

                // Mollie lists newest first, so the first entry created before the window ends the list.
                if ($created instanceof CarbonImmutable && $created->lessThan($since)) {
                    return;
                }

                yield $resource;
            }

            if (! $page->hasNext()) {
                return;
            }

            if ($read >= self::MAX_PAGES) {
                Log::warning("billing: Mollie lists more {$list} inside the window than one run reads, so the older ones were not checked", [
                    'pages' => $read,
                    'since' => $since->toIso8601String(),
                ]);

                return;
            }

            $page = MollieValue::narrow($page->next(), CursorCollection::class);
        }
    }

    /**
     * The customer a payment belongs to, or empty where it names none or Mollie no longer knows the payment.
     *
     * Only that refusal is an answer. Any other one says nothing about the payment and travels, so the run fails and
     * the next one reads the lists again.
     */
    private function customerOf(string $paymentId): string
    {
        $payment = $this->paymentOf($paymentId);

        return $payment instanceof Payment && is_string($payment->customerId) ? $payment->customerId : '';
    }

    /**
     * The payment an outcome names, read once per run, or null where it names none or Mollie no longer knows it.
     *
     * Any other refusal travels, so the run fails and the next one reads again.
     */
    private function paymentOf(string $paymentId): ?Payment
    {
        if ($paymentId === '') {
            return null;
        }

        if (array_key_exists($paymentId, $this->payments)) {
            return $this->payments[$paymentId];
        }

        try {
            $payment = MollieValue::narrow($this->client->send(new GetPaymentRequest($paymentId)), Payment::class);
        } catch (RequestException $refusal) {
            if (! in_array($refusal->getStatusCode(), [404, 410], true)) {
                throw $refusal;
            }

            $payment = null;
        }

        return $this->payments[$paymentId] = $payment instanceof Payment ? $payment : null;
    }

    /**
     * What a payment still has refunded or charged back: its refunded amount plus the chargebacks that still stand, read
     * the way the webhook reads it for `AddonRefunded`, in the currency of the reversed chargeback.
     *
     * Null where the payment is gone, its refunded amount cannot be read or is in another currency, or its chargebacks
     * are gone: a total read in part would give back money that is still owed, so the purchase is then left as it is. A
     * refusal that says nothing about the payment travels, as it does for the payment itself.
     */
    private function standingRefundedOf(string $paymentId, string $currency): ?Money
    {
        $payment = $this->paymentOf($paymentId);

        if (! $payment instanceof Payment) {
            return null;
        }

        try {
            $refunded = MollieAmount::fromOptionalResource($payment->amountRefunded, $currency);
        } catch (Throwable) {
            return null;
        }

        if ($refunded->currency !== $currency) {
            return null;
        }

        if (! $payment->hasChargebacks()) {
            return $refunded;
        }

        try {
            $chargebacks = $payment->chargebacks();
        } catch (RequestException $refusal) {
            if (! in_array($refusal->getStatusCode(), [404, 410], true)) {
                throw $refusal;
            }

            return null;
        }

        return $refunded->plus(MollieChargebackEvents::standing($chargebacks, $currency));
    }

    /**
     * The amount of a refund or a chargeback, or null with a warning where it cannot be read.
     *
     * An outcome without its amount is left out rather than reported as zero, and the warning names it, so it can be
     * found. The next run reads it again.
     */
    private function amountOf(mixed $amount, string $kind, string $id): ?Money
    {
        try {
            return MollieAmount::fromResource($amount);
        } catch (Throwable) {
            Log::warning("billing: Mollie reported a {$kind} amount this package cannot read, so it was left out", [
                $kind => $id,
                'amount' => $amount,
            ]);

            return null;
        }
    }

    /** A date Mollie sent, or null where it sent none or one that does not parse. */
    private function dateOf(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
