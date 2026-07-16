<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\PaymentMethods as PaymentMethodsContract;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\PaymentMethod as PaymentMethodValue;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\StripeClient;

/**
 * In-app payment-method management for the Stripe driver. It talks to Stripe's PaymentMethod / Customer
 * APIs directly and hands back neutral value objects, so the account hub never sees a Stripe object.
 * The billable's Stripe customer is resolved through the StripeCustomerRegistry, so adding a card to an
 * account that has never checked out creates a customer WITH the owner's identity on it — Stripe needs a
 * name and an email to put on the invoice and the receipt.
 *
 * Both mutating verbs confirm the method belongs to the billable's Stripe customer first: the method ids
 * are rendered into the browser and come back under the client's control, and a detach needs no customer
 * reference at all — so nothing but this check stands between a signed-in owner and a stranger's stored
 * card. A consequence of the check: removing an already-detached or unknown method now throws rather than
 * silently no-opping, because an id nobody owns is not one this billable may act on.
 */
final readonly class StripePaymentMethods implements PaymentMethodsContract
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private CheckoutUrls $urls,
    ) {}

    public function addMethodUrl(Model $billable): ?string
    {
        // A setup-mode Checkout Session: the same hosted, PCI-SAQ-A surface the subscription checkout uses,
        // pointed at collecting a card instead of a payment. The card is entered on Stripe's page and
        // attached to the customer; the customer is created with its identity if it does not exist yet, so
        // this works on the customer's very first visit.
        $return = $this->urls->paymentMethodsReturnUrl();

        if ($return === null) {
            return null;
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $this->customers->resolve($billable),
            'success_url' => $return,
            'cancel_url' => $return,
        ]);

        return is_string($session->url) ? $session->url : null;
    }

    public function setupIntent(Model $billable): ClientIntent
    {
        $customerId = $this->customers->resolve($billable);

        $intent = $this->stripe->setupIntents->create(['customer' => $customerId]);
        $secret = $intent->client_secret ?? null;

        return new ClientIntent(
            driver: 'stripe',
            payload: ['client_secret' => is_string($secret) ? $secret : ''],
            offSessionCapable: true,
        );
    }

    public function all(Model $billable): array
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return [];
        }

        $defaultId = $this->defaultMethodId($customerId);

        // Ask for the full set in one page (Stripe defaults to 10). A customer holding more than 100
        // saved methods is not a real case; without the limit the default card could fall off page one
        // and no entry would be flagged as default.
        $methods = $this->stripe->paymentMethods->all(['customer' => $customerId, 'limit' => 100]);

        $default = [];
        $rest = [];

        foreach ($methods->data as $method) {
            $isDefault = $method->id === $defaultId;
            $value = $this->toValue($method, $isDefault);

            if ($isDefault) {
                $default[] = $value;
            } else {
                $rest[] = $value;
            }
        }

        return [...$default, ...$rest];
    }

    public function default(Model $billable): ?PaymentMethodValue
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return null;
        }

        $defaultId = $this->defaultMethodId($customerId);

        if ($defaultId === null) {
            return null;
        }

        return $this->toValue($this->stripe->paymentMethods->retrieve($defaultId), true);
    }

    public function setDefault(Model $billable, string $methodId): void
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null || ! $this->ownsMethod($customerId, $methodId)) {
            throw new InvalidArgumentException('Cannot set a default payment method that does not belong to this billable.');
        }

        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $methodId],
        ]);
    }

    public function remove(Model $billable, string $methodId): void
    {
        $customerId = $this->customers->find($billable);

        // Detaching is GLOBAL to the method id: Stripe does not ask whose it is, so the customer check
        // is ours to make. Without it one owner's browser could detach another's card and break their
        // next renewal — the same guard the read path makes before streaming a document (StripeInvoices).
        if ($customerId === null || ! $this->ownsMethod($customerId, $methodId)) {
            throw new InvalidArgumentException('Cannot remove a payment method that does not belong to this billable.');
        }

        $this->stripe->paymentMethods->detach($methodId);
    }

    /** Whether the payment method is attached to this customer. An unknown or detached id is not owned. */
    private function ownsMethod(string $customerId, string $methodId): bool
    {
        try {
            $method = $this->stripe->paymentMethods->retrieve($methodId);
        } catch (InvalidRequestException) {
            return false;
        }

        $owner = $method->customer ?? null;

        // Stripe returns the customer as a bare id, or as an expanded object on some API versions.
        $ownerId = $owner instanceof Customer ? $owner->id : $owner;

        return is_string($ownerId) && $ownerId === $customerId;
    }

    /** The customer's default payment-method id from its invoice settings, or null. */
    private function defaultMethodId(string $customerId): ?string
    {
        $customer = $this->stripe->customers->retrieve($customerId, [
            'expand' => ['invoice_settings.default_payment_method'],
        ]);

        $settings = $customer->invoice_settings ?? null;

        if ($settings === null) {
            return null;
        }

        $default = $settings->default_payment_method ?? null;

        if (is_string($default)) {
            return $default;
        }

        if ($default instanceof StripePaymentMethod) {
            return $default->id;
        }

        return null;
    }

    private function toValue(StripePaymentMethod $method, bool $isDefault): PaymentMethodValue
    {
        $card = $method->card ?? null;

        return new PaymentMethodValue(
            id: $method->id,
            type: $method->type,
            isDefault: $isDefault,
            brand: $card?->brand,
            last4: $card?->last4,
            expMonth: $card?->exp_month,
            expYear: $card?->exp_year,
        );
    }
}
