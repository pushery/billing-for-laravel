<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use InvalidArgumentException;
use Pushery\Billing\Contracts\SubmitsDisputeEvidence;
use Pushery\Billing\ValueObjects\DisputeEvidence;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Submits a host's evidence on a Stripe dispute: each document uploaded as a file, then one update that
 * carries every statement and file and submits the case.
 *
 * A dispute on a connected account is answered on that account, and so are its files, because a file uploaded
 * to the platform cannot be referenced from another account.
 *
 * Every call carries an idempotency key built from the dispute and the fingerprint of the evidence. A retry
 * with the same evidence is answered by Stripe as the first call, so a job that fails after the update went
 * through cannot submit a second time or upload a second copy of a document. Different evidence for a case that
 * was already submitted reaches Stripe as a new call, and Stripe refuses it, because a submitted case cannot be
 * amended.
 */
final readonly class StripeDisputeEvidence implements SubmitsDisputeEvidence
{
    /** Stripe's evidence key for each statement of `DisputeEvidence`. */
    public const array STATEMENTS = [
        'productDescription' => 'product_description',
        'customerName' => 'customer_name',
        'customerEmail' => 'customer_email_address',
        'customerPurchaseIp' => 'customer_purchase_ip',
        'billingAddress' => 'billing_address',
        'serviceDate' => 'service_date',
        'accessActivityLog' => 'access_activity_log',
        'refundPolicyDisclosure' => 'refund_policy_disclosure',
        'refundRefusalExplanation' => 'refund_refusal_explanation',
        'cancellationPolicyDisclosure' => 'cancellation_policy_disclosure',
        'cancellationRebuttal' => 'cancellation_rebuttal',
        'duplicateChargeExplanation' => 'duplicate_charge_explanation',
        'duplicatePaymentReference' => 'duplicate_charge_id',
        'shippingAddress' => 'shipping_address',
        'shippingCarrier' => 'shipping_carrier',
        'shippingDate' => 'shipping_date',
        'shippingTrackingNumber' => 'shipping_tracking_number',
        'explanation' => 'uncategorized_text',
    ];

    /** Stripe's evidence key for each kind of document, keyed by the `DisputeDocument` value. */
    public const array DOCUMENTS = [
        'receipt' => 'receipt',
        'customer_communication' => 'customer_communication',
        'customer_signature' => 'customer_signature',
        'service_documentation' => 'service_documentation',
        'shipping_documentation' => 'shipping_documentation',
        'refund_policy' => 'refund_policy',
        'cancellation_policy' => 'cancellation_policy',
        'duplicate_charge_documentation' => 'duplicate_charge_documentation',
        'other' => 'uncategorized_file',
    ];

    public function __construct(private StripeClient $stripe) {}

    public function submit(string $disputeReference, DisputeEvidence $evidence, ?string $accountReference = null): void
    {
        if ($evidence->isEmpty()) {
            throw new InvalidArgumentException("No evidence to submit on dispute '{$disputeReference}'. Stripe decides the case on what arrives, so an empty submission gives it up.");
        }

        $key = 'dispute-evidence:'.$disputeReference.':'.$evidence->fingerprint();
        $account = $accountReference === null ? [] : ['stripe_account' => $accountReference];

        $fields = [];

        foreach ($evidence->statements() as $statement => $value) {
            $fields[self::STATEMENTS[$statement]] = $value;
        }

        foreach ($evidence->documents as $kind => $path) {
            $fields[self::DOCUMENTS[$kind]] = $this->upload($path, $key.':'.$kind, $account);
        }

        $this->stripe->disputes->update(
            $disputeReference,
            ['evidence' => $fields, 'submit' => true],
            ['idempotency_key' => $key, ...$account],
        );
    }

    /**
     * Uploads one document for dispute evidence and returns Stripe's reference for the file.
     *
     * @param  array{stripe_account?: string}  $account
     */
    private function upload(string $path, string $key, array $account): string
    {
        // DisputeEvidence refuses a document it cannot read when it is built, so failing here means the file went
        // away since.
        $handle = fopen($path, 'rb') ?: throw new RuntimeException("The dispute document at '{$path}' could not be opened.");

        try {
            return $this->stripe->files->create(
                ['purpose' => 'dispute_evidence', 'file' => $handle],
                ['idempotency_key' => $key, ...$account],
            )->id;
        } finally {
            fclose($handle);
        }
    }
}
