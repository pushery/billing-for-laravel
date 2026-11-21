<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Requests\CreatePaymentCaptureRequest;
use Mollie\Api\Http\Requests\ReleasePaymentAuthorizationRequest;
use Mollie\Api\Resources\Capture;
use Pushery\Billing\ValueObjects\CaptureResult;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\ValueObjects\PaymentCancellation;

/**
 * Captures an authorized payment of a payment account at Mollie, in full or in part, or releases it.
 *
 * Klarna, in3, Billie and Riverty end a payment in `authorized`, and so does a card payment created with the capture
 * mode `manual`. The money moves once the payment is captured, usually when the order ships. Released instead, the
 * hold ends and nothing moves: Mollie cancels an authorized payment through its own release, not through the
 * cancellation of an open one. What the provider refuses comes back as a result with Mollie's status and words; an
 * error that says nothing about the payment travels.
 */
final readonly class MollieCaptures
{
    public function __construct(
        private MollieClientFactory $clients = new MollieClientFactory,
    ) {}

    /**
     * @param  ?Money  $amount  the part to capture, or null for the whole authorized amount
     * @param  string  $description  the text Mollie keeps with the capture
     */
    public function capture(PaymentAccount $account, string $paymentReference, ?Money $amount = null, string $description = 'Capture'): CaptureResult
    {
        try {
            $capture = $this->clients->forAccount($account)->send(new CreatePaymentCaptureRequest(
                $paymentReference,
                $description,
                $amount instanceof Money ? MollieAmount::toMollie($amount) : null,
            ));
        } catch (RequestException $refusal) {
            $status = $refusal->getStatusCode();

            if ($status < 400 || $status >= 500 || in_array($status, [401, 403, 429], true)) {
                throw $refusal;
            }

            return new CaptureResult(
                successful: false,
                reference: '',
                amount: $amount,
                failureCode: (string) $status,
                failureMessage: $refusal instanceof ApiException ? $refusal->getPlainMessage() : $refusal->getMessage(),
            );
        }

        $capture = MollieValue::narrow($capture, Capture::class);
        $status = $capture instanceof Capture ? MollieValue::of($capture->status) : null;

        return new CaptureResult(
            successful: $status !== 'failed',
            reference: $capture instanceof Capture ? MollieValue::id($capture->id) : '',
            amount: $capture instanceof Capture && isset($capture->amount) ? MollieAmount::fromResource($capture->amount) : $amount,
            status: $status,
            failureCode: $status === 'failed' ? $status : null,
        );
    }

    /**
     * End the hold of an authorized payment, so nothing moves. Mollie takes the release and reports the payment
     * canceled through its webhook; a payment it keeps, one already captured for instance, comes back as not released.
     */
    public function release(PaymentAccount $account, string $paymentReference): PaymentCancellation
    {
        try {
            $this->clients->forAccount($account)->send(new ReleasePaymentAuthorizationRequest($paymentReference));
        } catch (RequestException $refusal) {
            $status = $refusal->getStatusCode();

            if ($status < 400 || $status >= 500 || in_array($status, [401, 403, 429], true)) {
                throw $refusal;
            }

            return new PaymentCancellation(
                $paymentReference,
                canceled: false,
                failureCode: (string) $status,
                failureMessage: $refusal instanceof ApiException ? $refusal->getPlainMessage() : $refusal->getMessage(),
            );
        }

        return new PaymentCancellation($paymentReference, canceled: true);
    }
}
