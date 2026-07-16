<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The Stripe implementation of the lower billing layer — it moves money and stores mandates through
 * Stripe's PaymentIntent / PaymentMethod / Refund APIs, and returns only neutral value objects so the
 * engine above never touches a Stripe response.
 *
 * A card decline is a business outcome, not an error: it comes back as a failed {@see ChargeResult}.
 * Every other Stripe error (bad request, missing customer, connection failure) propagates, because it
 * signals a misconfiguration the caller must not silently treat as "the customer's card was declined".
 *
 * A charge has more than two outcomes: besides settled and declined, it may need the cardholder to
 * authenticate (3-D Secure) or be a bank debit still processing (SEPA). Those are carried out as
 * requires-action / pending on the ChargeResult, never collapsed onto "declined".
 */
final readonly class StripePaymentRails implements PaymentRails
{
    public function __construct(private StripeClient $stripe) {}

    public function charge(Money $amount, string $token, ?string $idempotencyKey = null): ChargeResult
    {
        return $this->settle(fn (): PaymentIntent => $this->stripe->paymentIntents->create([
            'amount' => $amount->minorUnits,
            'currency' => strtolower($amount->currency),
            'payment_method' => $token,
            'confirm' => true,
            'off_session' => false,
        ], $this->options($idempotencyKey)), $amount);
    }

    public function createMandate(string $customerReference, string $token): MandateReference
    {
        $method = $this->stripe->paymentMethods->attach($token, ['customer' => $customerReference]);

        return new MandateReference(
            id: $method->id,
            method: $method->type,
            reusable: $this->isReusable($method->type),
            customerReference: $customerReference,
        );
    }

    public function tokenize(string $paymentData): TokenizedMethod
    {
        $method = $this->stripe->paymentMethods->retrieve($paymentData);

        // A non-card method carries no `card` hash. The `??` uses Stripe's
        // notice-free __isset, so reading a card-less method neither warns nor
        // fabricates a brand/last4.
        $card = $method->card ?? null;

        return new TokenizedMethod(
            token: $method->id,
            offSessionCapable: $this->isReusable($method->type),
            brand: $card?->brand,
            last4: $card?->last4,
        );
    }

    /**
     * Charge a stored mandate off-session (merchant-initiated). The mandate id is the Stripe
     * payment-method reference {@see createMandate()} attached to the customer; Stripe resolves the
     * customer from the attached method, and `off_session: true` flags the absent-cardholder intent so
     * the correct SCA exemption is requested.
     */
    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null): ChargeResult
    {
        $customer = $mandate->customerReference;
        $options = $this->options($idempotencyKey);

        // Stripe needs the customer to charge a stored payment method off-session; a mandate created
        // by createMandate() carries it. Without it, fall back to a payment-method-only intent.
        return $this->settle(fn (): PaymentIntent => $this->stripe->paymentIntents->create(
            $customer !== null
                ? [
                    'amount' => $amount->minorUnits,
                    'currency' => strtolower($amount->currency),
                    'payment_method' => $mandate->id,
                    'confirm' => true,
                    'off_session' => true,
                    'customer' => $customer,
                ]
                : [
                    'amount' => $amount->minorUnits,
                    'currency' => strtolower($amount->currency),
                    'payment_method' => $mandate->id,
                    'confirm' => true,
                    'off_session' => true,
                ],
            $options,
        ), $amount);
    }

    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null): RefundResult
    {
        $refund = $this->stripe->refunds->create([
            'payment_intent' => $chargeReference,
            'amount' => $amount->minorUnits,
        ], $this->options($idempotencyKey));

        return new RefundResult(
            successful: $refund->status === 'succeeded' || $refund->status === 'pending',
            reference: $refund->id,
            amount: $amount,
        );
    }

    /**
     * Run a PaymentIntent creation and translate the outcome to a neutral ChargeResult. A card decline
     * (the one recoverable business outcome) is caught and returned as a failure; any other Stripe
     * error propagates.
     *
     * @param  callable(): PaymentIntent  $create
     */
    private function settle(callable $create, Money $amount): ChargeResult
    {
        try {
            $intent = $create();
        } catch (CardException $e) {
            return new ChargeResult(
                successful: false,
                reference: '',
                amount: $amount,
                failureReason: $e->getMessage(),
            );
        }

        return $this->outcomeFor($intent, $amount);
    }

    /**
     * Translate a PaymentIntent status onto the neutral outcome. Three of these are NOT declines:
     * `succeeded` is settled money; `requires_action` needs the cardholder to authenticate (3-D Secure),
     * so the client secret is carried out for the front end to confirm against; `processing` is a bank
     * debit (SEPA) still in flight. Everything else — a canceled or requires_payment_method intent — is a
     * genuine failure. Reporting an authentication or a pending debit as a decline is how a good European
     * payment gets counted as a loss.
     */
    private function outcomeFor(PaymentIntent $intent, Money $amount): ChargeResult
    {
        return match ($intent->status) {
            'succeeded' => new ChargeResult(successful: true, reference: $intent->id, amount: $amount),
            'requires_action', 'requires_confirmation' => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
                requiresAction: true,
                clientSecret: is_string($intent->client_secret) ? $intent->client_secret : null,
            ),
            'processing' => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
                pending: true,
            ),
            default => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
            ),
        };
    }

    /**
     * The Stripe per-request options carrying the idempotency key, or empty when none is given. A
     * stable key makes a retried money-moving request collapse onto the first result rather than
     * charging or refunding twice.
     *
     * @return array{idempotency_key?: string}
     */
    private function options(?string $idempotencyKey): array
    {
        return $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];
    }

    /**
     * Whether a payment-method type can be charged off-session (stored for reuse). Single-use,
     * redirect-only methods cannot, so a mandate over them is not reusable.
     */
    private function isReusable(string $type): bool
    {
        return ! in_array($type, ['ideal', 'bancontact', 'sofort', 'p24', 'giropay', 'eps'], true);
    }
}
