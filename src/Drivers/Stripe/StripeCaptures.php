<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\CaptureResult;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\ValueObjects\PaymentCancellation;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Captures or releases an authorized payment of a payment account at Stripe.
 *
 * A charge made with the capture mode `manual` ends authorized: Stripe holds the amount on the customer's payment
 * method, and moves it once the payment is captured, in full or in part. A part captured releases the rest, since
 * Stripe captures a payment once. Released instead, the hold ends and nothing moves. The reference is the one the
 * charge returned: a Checkout Session, whose payment intent is read first, or the payment intent itself. What Stripe
 * refuses comes back with Stripe's code and words; an error that says nothing about the payment travels.
 */
final readonly class StripeCaptures
{
    public function __construct(private Repository $config) {}

    /**
     * @param  ?Money  $amount  the part to capture, or null for the whole authorized amount
     */
    public function capture(PaymentAccount $account, string $paymentReference, ?Money $amount = null): CaptureResult
    {
        $client = $this->client($account);

        try {
            $intentReference = $this->intentOf($client, $paymentReference);

            if ($intentReference === null) {
                return new CaptureResult(
                    successful: false,
                    reference: '',
                    amount: $amount,
                    failureCode: 'no_payment_intent',
                    failureMessage: "The Checkout Session `{$paymentReference}` has no payment to capture yet.",
                );
            }

            $intent = $client->paymentIntents->capture(
                $intentReference,
                $amount instanceof Money ? ['amount_to_capture' => StripeAmount::of($amount)] : [],
            );
        } catch (RateLimitException $rateLimited) {
            // A 429 is an InvalidRequestException as well, and it answers nothing about the payment.
            throw $rateLimited;
        } catch (ApiErrorException $refusal) {
            if (! $this->refuses($refusal)) {
                throw $refusal;
            }

            return new CaptureResult(
                successful: false,
                reference: '',
                amount: $amount,
                failureCode: $this->codeOf($refusal),
                failureMessage: $refusal->getMessage(),
            );
        }

        return new CaptureResult(
            successful: $intent->status === PaymentIntent::STATUS_SUCCEEDED,
            reference: $intent->id,
            amount: $this->received($intent) ?? $amount,
            status: $intent->status,
        );
    }

    /**
     * End the hold of an authorized payment, so nothing moves. A payment Stripe has already captured, or one it keeps
     * for another reason, comes back as not released, with Stripe's code and words.
     */
    public function release(PaymentAccount $account, string $paymentReference): PaymentCancellation
    {
        $client = $this->client($account);

        try {
            $intentReference = $this->intentOf($client, $paymentReference);

            if ($intentReference === null) {
                return new PaymentCancellation(
                    $paymentReference,
                    canceled: false,
                    failureCode: 'no_payment_intent',
                    failureMessage: "The Checkout Session `{$paymentReference}` holds no payment yet; expire the session instead.",
                );
            }

            $intent = $client->paymentIntents->cancel($intentReference);
        } catch (RateLimitException $rateLimited) {
            // A 429 is an InvalidRequestException as well, and it answers nothing about the payment.
            throw $rateLimited;
        } catch (ApiErrorException $refusal) {
            if (! $this->refuses($refusal)) {
                throw $refusal;
            }

            return new PaymentCancellation(
                $paymentReference,
                canceled: false,
                failureCode: $this->codeOf($refusal),
                failureMessage: $refusal->getMessage(),
            );
        }

        $released = $intent->status === PaymentIntent::STATUS_CANCELED;

        return new PaymentCancellation($paymentReference, canceled: $released, failureCode: $released ? null : $intent->status);
    }

    /**
     * The payment intent a reference names: a Checkout Session's own once its customer has paid, null before that.
     */
    private function intentOf(StripeClient $client, string $reference): ?string
    {
        if (! str_starts_with($reference, 'cs_')) {
            return $reference;
        }

        $intent = $client->checkout->sessions->retrieve($reference)->payment_intent;

        if ($intent instanceof PaymentIntent) {
            return $intent->id;
        }

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    private function client(PaymentAccount $account): TimedStripeClient
    {
        $version = $this->config->get('billing.stripe.api_version');

        return StripeAccountClient::for($account, is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION);
    }

    /** What the customer was charged, where the intent says it. */
    private function received(PaymentIntent $intent): ?Money
    {
        $amount = $intent->amount_received;

        if ($amount <= 0) {
            return null;
        }

        return StripeAmount::toMoney($amount, $intent->currency);
    }

    /** Whether Stripe refused the request for this payment, rather than failing to answer or refusing the account. */
    private function refuses(ApiErrorException $error): bool
    {
        $status = $error->getHttpStatus();

        return is_int($status) && $status >= 400 && $status < 500 && ! in_array($status, [401, 403, 429], true);
    }

    private function codeOf(ApiErrorException $error): string
    {
        $code = $error->getStripeCode();

        return is_string($code) && $code !== '' ? $code : (string) $error->getHttpStatus();
    }
}
