<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Mollie\Api\Types\MandateMethod;
use Pushery\Billing\Exceptions\MollieNotConfigured;

/**
 * Mollie without Mollie: the customers, payments, mandates and refunds of the sandbox, kept in its store as the objects
 * Mollie answers with, and moved between their states the way Mollie moves them.
 *
 * A payment the customer has to complete, a first or a one-off one, waits `open` with a checkout of this application,
 * {@see SandboxCheckout}, which completes it with the outcome `billing.mollie.sandbox_outcome` names. A paid first
 * payment leaves a valid mandate behind, as on Mollie. A payment against a mandate gets the outcome at once, the way a
 * card payment can come back from Mollie. Every payment whose status moved is pinged to its webhook URL.
 *
 * Ids are counted per kind, `cst_sbx000001`, `tr_sbx000001`, `mdt_sbx000001` and `re_sbx000001`, so a run that starts
 * from an empty store hands out the same ids every time.
 */
final readonly class SandboxMollie
{
    /** @var list<string> The outcomes a payment can be given. */
    public const array OUTCOMES = ['paid', 'failed', 'canceled', 'expired'];

    /** The one profile of the sandbox, on every payment that names none. */
    public const string PROFILE = 'pfl_sbx000001';

    public function __construct(private SandboxStore $store, private string $outcome) {}

    /** Whether the sandbox answers for Mollie in this install. */
    public static function enabled(): bool
    {
        return Config::boolean('billing.mollie.sandbox', false);
    }

    /**
     * The sandbox as configured: its store and the outcome it gives each payment.
     *
     * @throws MollieNotConfigured when `billing.mollie.sandbox_outcome` names no outcome the sandbox knows
     */
    public static function fromConfig(): self
    {
        $store = Config::get('billing.mollie.sandbox_store');
        $outcome = Config::get('billing.mollie.sandbox_outcome', 'paid');

        if (! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)) {
            throw MollieNotConfigured::unknownSandboxOutcome(is_string($outcome) ? $outcome : get_debug_type($outcome));
        }

        return new self(new SandboxStore(Cache::store(is_string($store) && $store !== '' ? $store : null)), $outcome);
    }

    /**
     * The object stored under the id, or null for an id the sandbox never issued.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->store->find($id);
    }

    /** Whether the sandbox itself issued this checkout for the payment, which is what lets the driver send a customer there. */
    public function issuedCheckout(string $payment, string $url): bool
    {
        $links = $this->store->find($payment)['_links'] ?? null;
        $checkout = is_array($links) ? ($links['checkout'] ?? null) : null;
        $href = is_array($checkout) ? ($checkout['href'] ?? null) : null;

        return is_string($href) && $href === $url;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array
    {
        $id = $this->store->nextId('cst');
        $customer = [
            'resource' => 'customer',
            'id' => $id,
            'mode' => 'test',
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'locale' => $payload['locale'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'createdAt' => $this->now(),
            '_links' => ['self' => $this->link('customers/'.$id)],
        ];

        $this->store->put($id, $customer);

        return $customer;
    }

    /** @param  array<string, mixed>  $customer */
    public function deleteCustomer(array $customer): void
    {
        $this->store->forget($this->idOf($customer));
    }

    /**
     * A mandate the account holder gave on the application's own form, valid at once.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createMandate(string $customer, array $payload): array
    {
        return $this->mandate(
            $customer,
            is_string($payload['method'] ?? null) ? $payload['method'] : 'directdebit',
            [
                'consumerName' => is_string($payload['consumerName'] ?? null) ? $payload['consumerName'] : null,
                'consumerAccount' => is_string($payload['consumerAccount'] ?? null) ? $payload['consumerAccount'] : null,
                'consumerBic' => is_string($payload['consumerBic'] ?? null) ? $payload['consumerBic'] : null,
            ],
            $payload['mandateReference'] ?? null,
            $payload['signatureDate'] ?? null,
        );
    }

    /**
     * Revoke the mandate. It stays readable, as `invalid`, and nothing can be charged against it any more.
     *
     * @param  array<string, mixed>  $mandate
     */
    public function revokeMandate(array $mandate): void
    {
        $mandate['status'] = 'invalid';

        $this->store->put($this->idOf($mandate), $mandate);
    }

    /**
     * A new payment. One the customer has to complete waits `open` with the sandbox's checkout; one against a mandate
     * gets the outcome at once and is pinged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SandboxRefusal when a payment against a mandate names no valid mandate of its customer
     */
    public function createPayment(array $payload): array
    {
        $sequence = is_string($payload['sequenceType'] ?? null) ? $payload['sequenceType'] : 'oneoff';
        $amount = $this->amountOf($payload['amount'] ?? null);
        $mandate = null;

        if ($sequence === 'recurring') {
            $mandateId = $payload['mandateId'] ?? null;
            $mandate = is_string($mandateId) ? $this->store->find($mandateId) : null;

            if ($mandate === null || ($mandate['status'] ?? null) !== 'valid') {
                throw new SandboxRefusal(422, 'The sandbox charges a recurring payment against a valid mandate only, and this one names none.');
            }
        }

        $id = $this->store->nextId('tr');
        $payment = [
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'createdAt' => $this->now(),
            'amount' => $amount,
            'amountRefunded' => ['value' => $this->value(0, $amount['value']), 'currency' => $amount['currency']],
            'amountRemaining' => $amount,
            'description' => is_string($payload['description'] ?? null) ? $payload['description'] : '',
            'method' => is_string($payload['method'] ?? null) ? $payload['method'] : null,
            'metadata' => $payload['metadata'] ?? null,
            'status' => 'open',
            'isCancelable' => true,
            'profileId' => is_string($payload['profileId'] ?? null) ? $payload['profileId'] : self::PROFILE,
            'sequenceType' => $sequence,
            'redirectUrl' => $payload['redirectUrl'] ?? null,
            'webhookUrl' => $payload['webhookUrl'] ?? null,
            'customerId' => $payload['customerId'] ?? null,
            'mandateId' => $payload['mandateId'] ?? null,
            '_links' => ['self' => $this->link('payments/'.$id)],
        ];

        if ($mandate !== null) {
            $payment['method'] = ($mandate['method'] ?? null) === 'creditcard' ? 'creditcard' : 'directdebit';
            $payment = $this->settled($payment, $this->outcome);

            $this->store->put($id, $payment);
            $this->ping($payment, false);

            return $payment;
        }

        $payment['expiresAt'] = Date::now()->utc()->addMinutes(15)->format(DATE_ATOM);
        $payment['_links']['checkout'] = [
            'href' => URL::signedRoute('billing.mollie.sandbox.checkout', ['payment' => $id]),
            'type' => 'text/html',
        ];

        $this->store->put($id, $payment);

        return $payment;
    }

    /**
     * Complete an open payment with the configured outcome, the way its customer would on Mollie's checkout, and ping
     * it at once. A paid first payment leaves a valid mandate behind. A payment that is no longer open stays as it is.
     * Null for a payment the sandbox does not know.
     *
     * @return array<string, mixed>|null
     */
    public function complete(string $id): ?array
    {
        $payment = $this->store->find($id);

        if ($payment === null || ($payment['resource'] ?? null) !== 'payment') {
            return null;
        }

        if (($payment['status'] ?? null) !== 'open') {
            return $payment;
        }

        $method = is_string($payment['method'] ?? null) ? $payment['method'] : 'creditcard';
        $payment['method'] = $method;
        $payment = $this->settled($payment, $this->outcome);

        $customer = $payment['customerId'] ?? null;

        if ($this->outcome === 'paid' && ($payment['sequenceType'] ?? null) === 'first' && is_string($customer)) {
            $kind = MandateMethod::getForFirstPaymentMethod($method);
            $payment['mandateId'] = $this->mandate($customer, $kind, $this->detailsOf($kind), null, null)['id'];
        }

        $this->store->put($id, $payment);
        $this->ping($payment, true);

        return $payment;
    }

    /**
     * Cancel an open payment, and ping it.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     *
     * @throws SandboxRefusal when the payment is no longer open
     */
    public function cancel(array $payment): array
    {
        if (($payment['status'] ?? null) !== 'open') {
            throw new SandboxRefusal(422, 'The payment '.$this->idOf($payment).' is not open, so it cannot be canceled.');
        }

        $payment = $this->settled($payment, 'canceled');

        $this->store->put($this->idOf($payment), $payment);
        $this->ping($payment, false);

        return $payment;
    }

    /**
     * Refund a paid payment, in full or in part. The refund is `refunded` at once, and the payment is pinged.
     *
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SandboxRefusal when the payment is not paid, or the amount is more than what remains of it
     */
    public function refund(array $payment, array $payload): array
    {
        $id = $this->idOf($payment);

        if (($payment['status'] ?? null) !== 'paid') {
            throw new SandboxRefusal(422, "The payment {$id} is not paid, so it cannot be refunded.");
        }

        $amount = $this->amountOf($payload['amount'] ?? $payment['amount']);
        $remaining = $this->amountOf($payment['amountRemaining'] ?? $payment['amount']);
        $refunded = $this->amountOf($payment['amountRefunded'] ?? null);
        $left = $this->minor($remaining['value']) - $this->minor($amount['value']);

        if ($left < 0) {
            throw new SandboxRefusal(422, "The amount is higher than what remains of the payment {$id}.");
        }

        $refundId = $this->store->nextId('re');
        $refund = [
            'resource' => 'refund',
            'id' => $refundId,
            'mode' => 'test',
            'amount' => $amount,
            'status' => 'refunded',
            'paymentId' => $id,
            'description' => is_string($payload['description'] ?? null) ? $payload['description'] : '',
            'metadata' => $payload['metadata'] ?? null,
            'createdAt' => $this->now(),
            '_links' => ['self' => $this->link("payments/{$id}/refunds/{$refundId}"), 'payment' => $this->link('payments/'.$id)],
        ];

        $this->store->put($refundId, $refund);
        $this->store->join('refunds:'.$id, $refundId);
        $this->store->join('refunds', $refundId);

        $payment['amountRemaining'] = ['value' => $this->value($left, $remaining['value']), 'currency' => $remaining['currency']];
        $payment['amountRefunded'] = [
            'value' => $this->value($this->minor($refunded['value']) + $this->minor($amount['value']), $amount['value']),
            'currency' => $amount['currency'],
        ];
        $payment['_links'] = [...(is_array($payment['_links'] ?? null) ? $payment['_links'] : []), 'refunds' => $this->link("payments/{$id}/refunds")];

        $this->store->put($id, $payment);
        $this->ping($payment, false);

        return $refund;
    }

    /**
     * The objects of a list as Mollie pages them, all on one page.
     *
     * @return array<string, mixed>
     */
    public function page(string $embedded, string $list, string $path): array
    {
        $items = [];

        foreach ($this->store->members($list) as $id) {
            $object = $this->store->find($id);

            if ($object !== null) {
                $items[] = $object;
            }
        }

        return $this->pageOf($embedded, $items, $path);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function pageOf(string $embedded, array $items, string $path): array
    {
        return [
            'count' => count($items),
            '_embedded' => [$embedded => $items],
            '_links' => ['self' => $this->link($path), 'previous' => null, 'next' => null],
        ];
    }

    /**
     * The answer a request under this idempotency key got, or null for a key not seen yet.
     *
     * @return array{status: int, body: string}|null
     */
    public function answered(string $key): ?array
    {
        return $this->store->answered($key);
    }

    public function remember(string $key, int $status, string $body): void
    {
        $this->store->remember($key, $status, $body);
    }

    /**
     * @param  array<string, string|null>  $details
     * @return array<string, mixed>
     */
    private function mandate(string $customer, string $method, array $details, mixed $reference, mixed $signatureDate): array
    {
        $id = $this->store->nextId('mdt');
        $mandate = [
            'resource' => 'mandate',
            'id' => $id,
            'mode' => 'test',
            'status' => 'valid',
            'method' => $method,
            'details' => $details,
            'mandateReference' => is_string($reference) ? $reference : null,
            'signatureDate' => is_string($signatureDate) ? $signatureDate : Date::now()->utc()->format('Y-m-d'),
            'createdAt' => $this->now(),
            '_links' => ['self' => $this->link("customers/{$customer}/mandates/{$id}"), 'customer' => $this->link('customers/'.$customer)],
        ];

        $this->store->put($id, $mandate);
        $this->store->join('mandates:'.$customer, $id);

        return $mandate;
    }

    /**
     * What a mandate of this kind says about the method behind it: a card for a card mandate, an account otherwise,
     * Mollie's own test account.
     *
     * @return array<string, string|null>
     */
    private function detailsOf(string $kind): array
    {
        if ($kind === 'creditcard') {
            return [
                'cardHolder' => 'Sandbox',
                'cardNumber' => '4242',
                'cardLabel' => 'Visa',
                'cardFingerprint' => 'sandbox',
                'cardExpiryDate' => Date::now()->utc()->addYears(3)->endOfYear()->format('Y-m-d'),
            ];
        }

        return ['consumerName' => 'Sandbox', 'consumerAccount' => 'NL55INGB0000000000', 'consumerBic' => 'INGBNL2A'];
    }

    /**
     * The payment moved to the outcome, with the moment Mollie stamps it with.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function settled(array $payment, string $outcome): array
    {
        $payment['status'] = $outcome;
        $payment['isCancelable'] = false;
        $payment[$outcome.'At'] = $this->now();

        if ($outcome === 'failed') {
            $payment['statusReason'] = [
                'code' => 'insufficient_funds',
                'message' => 'The sandbox refused this payment, as billing.mollie.sandbox_outcome asks it to.',
            ];
        }

        $links = is_array($payment['_links'] ?? null) ? $payment['_links'] : [];
        unset($payment['expiresAt'], $links['checkout']);
        $payment['_links'] = $links;

        return $payment;
    }

    /**
     * Ping the payment to its webhook URL: at once from the checkout, where the customer waits for the answer, and as
     * a job otherwise, after the payment's answer has gone back to the driver.
     *
     * @param  array<string, mixed>  $payment
     */
    private function ping(array $payment, bool $now): void
    {
        $url = $payment['webhookUrl'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $ping = new SendSandboxPing($this->idOf($payment), $url);

        if ($now) {
            Bus::dispatchSync($ping);

            return;
        }

        Bus::dispatch($ping);
    }

    /** @return array{value: string, currency: string} */
    private function amountOf(mixed $amount): array
    {
        $value = is_array($amount) && is_string($amount['value'] ?? null) ? $amount['value'] : '0.00';
        $currency = is_array($amount) && is_string($amount['currency'] ?? null) ? $amount['currency'] : 'EUR';

        return ['value' => $value, 'currency' => $currency];
    }

    /** The amount Mollie writes as text, in minor units: `12.50` is 1250. */
    private function minor(string $value): int
    {
        return (int) str_replace('.', '', $value);
    }

    /** Minor units written as Mollie writes an amount with as many decimals as `$like` has: 1250 like `0.00` is `12.50`. */
    private function value(int $minor, string $like): string
    {
        $dot = strrpos($like, '.');
        $decimals = $dot === false ? 0 : strlen($like) - $dot - 1;
        $digits = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $decimals);

        return ($minor < 0 ? '-' : '').$whole.($decimals > 0 ? '.'.substr($digits, -$decimals) : '');
    }

    /** @param  array<string, mixed>  $object */
    private function idOf(array $object): string
    {
        return is_string($object['id'] ?? null) ? $object['id'] : '';
    }

    /** @return array{href: string, type: string} */
    private function link(string $path): array
    {
        return ['href' => 'https://api.mollie.com/v2/'.$path, 'type' => 'application/hal+json'];
    }

    private function now(): string
    {
        return Date::now()->utc()->format(DATE_ATOM);
    }
}
