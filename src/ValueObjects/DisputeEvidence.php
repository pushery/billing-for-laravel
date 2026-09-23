<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\DisputeDocument;

/**
 * What a host submits to answer a dispute: statements about the sale, and documents that prove them.
 *
 * Every field is optional, because which evidence answers a case depends on what the buyer claims. A buyer who
 * says the goods never arrived is answered with the shipping details, one who calls a charge a duplicate with
 * the other purchase. `DisputeOpened::$reasonCode` says which case it is.
 *
 * A document is a local file the submitting process can read, keyed by the kind of proof it is. The files are
 * checked when the evidence is built, so a path that cannot be read fails here rather than halfway through a
 * submission the provider treats as final.
 */
final readonly class DisputeEvidence
{
    /**
     * @param  array<string, string>  $documents  a readable file per kind of document, keyed by a `DisputeDocument` value
     */
    public function __construct(
        /** What was sold, in words a card network's reviewer can follow. */
        public ?string $productDescription = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        /** The IP address the purchase was made from. */
        public ?string $customerPurchaseIp = null,
        public ?string $billingAddress = null,
        /** When the service was provided, or began. */
        public ?string $serviceDate = null,
        /** The buyer's use of the product after the purchase, such as sign-ins or downloads. */
        public ?string $accessActivityLog = null,
        /** How and when the refund policy was shown to the buyer. */
        public ?string $refundPolicyDisclosure = null,
        /** Why the buyer is not entitled to a refund. */
        public ?string $refundRefusalExplanation = null,
        /** How and when the cancellation policy was shown to the buyer. */
        public ?string $cancellationPolicyDisclosure = null,
        /** Why the buyer's subscription was not canceled, or not in time. */
        public ?string $cancellationRebuttal = null,
        /** Why a charge the buyer calls a duplicate is a separate purchase. */
        public ?string $duplicateChargeExplanation = null,
        /** The provider's reference for the other payment the buyer calls the original. */
        public ?string $duplicatePaymentReference = null,
        public ?string $shippingAddress = null,
        public ?string $shippingCarrier = null,
        public ?string $shippingDate = null,
        public ?string $shippingTrackingNumber = null,
        /** Anything else the reviewer should read, in the host's own words. */
        public ?string $explanation = null,
        public array $documents = [],
    ) {
        foreach ($documents as $kind => $path) {
            if (! DisputeDocument::tryFrom($kind) instanceof DisputeDocument) {
                throw new InvalidArgumentException("'{$kind}' is not a kind of dispute document. Use a DisputeDocument value.");
            }

            if (! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException("The {$kind} document at '{$path}' cannot be read.");
            }
        }
    }

    /** The same evidence with one more document, or with the document of that kind replaced. */
    public function withDocument(DisputeDocument $kind, string $path): self
    {
        return new self(
            productDescription: $this->productDescription,
            customerName: $this->customerName,
            customerEmail: $this->customerEmail,
            customerPurchaseIp: $this->customerPurchaseIp,
            billingAddress: $this->billingAddress,
            serviceDate: $this->serviceDate,
            accessActivityLog: $this->accessActivityLog,
            refundPolicyDisclosure: $this->refundPolicyDisclosure,
            refundRefusalExplanation: $this->refundRefusalExplanation,
            cancellationPolicyDisclosure: $this->cancellationPolicyDisclosure,
            cancellationRebuttal: $this->cancellationRebuttal,
            duplicateChargeExplanation: $this->duplicateChargeExplanation,
            duplicatePaymentReference: $this->duplicatePaymentReference,
            shippingAddress: $this->shippingAddress,
            shippingCarrier: $this->shippingCarrier,
            shippingDate: $this->shippingDate,
            shippingTrackingNumber: $this->shippingTrackingNumber,
            explanation: $this->explanation,
            documents: [...$this->documents, $kind->value => $path],
        );
    }

    /**
     * Every statement, keyed by its field name, including the empty ones.
     *
     * @return array<string, ?string>
     */
    public function fields(): array
    {
        return [
            'productDescription' => $this->productDescription,
            'customerName' => $this->customerName,
            'customerEmail' => $this->customerEmail,
            'customerPurchaseIp' => $this->customerPurchaseIp,
            'billingAddress' => $this->billingAddress,
            'serviceDate' => $this->serviceDate,
            'accessActivityLog' => $this->accessActivityLog,
            'refundPolicyDisclosure' => $this->refundPolicyDisclosure,
            'refundRefusalExplanation' => $this->refundRefusalExplanation,
            'cancellationPolicyDisclosure' => $this->cancellationPolicyDisclosure,
            'cancellationRebuttal' => $this->cancellationRebuttal,
            'duplicateChargeExplanation' => $this->duplicateChargeExplanation,
            'duplicatePaymentReference' => $this->duplicatePaymentReference,
            'shippingAddress' => $this->shippingAddress,
            'shippingCarrier' => $this->shippingCarrier,
            'shippingDate' => $this->shippingDate,
            'shippingTrackingNumber' => $this->shippingTrackingNumber,
            'explanation' => $this->explanation,
        ];
    }

    /**
     * The statements that carry something. An empty string says nothing and is left out like a null.
     *
     * @return array<string, string>
     */
    public function statements(): array
    {
        return array_filter($this->fields(), static fn (?string $value): bool => $value !== null && trim($value) !== '');
    }

    /**
     * Whether there is nothing to submit.
     *
     * Submitting nothing is not a neutral act: the provider closes the case on what arrived, so an empty
     * submission gives the case up.
     */
    public function isEmpty(): bool
    {
        return $this->statements() === [] && $this->documents === [];
    }

    /**
     * A digest of what would be submitted: the statements, and the content of every document.
     *
     * Two submissions with the same fingerprint are the same answer, whatever order they were built in and
     * wherever the files live. A driver keys its retries on it.
     */
    public function fingerprint(): string
    {
        $statements = $this->statements();
        ksort($statements);

        $documents = array_map(static fn (string $path): string => (string) hash_file('sha256', $path), $this->documents);
        ksort($documents);

        return hash('sha256', json_encode([$statements, $documents], JSON_THROW_ON_ERROR));
    }
}
