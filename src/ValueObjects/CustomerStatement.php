<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\Enums\CustomerType;

/**
 * What a customer confirmed about themselves at checkout, before it is written down.
 *
 * The wording is the application's: the package cannot know which sentence a customer was shown, and a declaration
 * without the sentence is no evidence of what was declared. A business names itself and its address, and gives its VAT
 * number where it has one. The consent to the renewal terms of an annual subscription is its own answer, given at its
 * own checkbox, so it is null where the checkout asked nothing and carries the wording wherever it was asked.
 */
final readonly class CustomerStatement
{
    /**
     * @throws InvalidArgumentException when a part the declaration needs is missing, or a consumer names a business
     */
    public function __construct(
        public CustomerType $type,
        public string $statement,
        public ?string $statementVersion = null,
        public ?string $businessName = null,
        public ?string $businessAddress = null,
        public ?string $vatId = null,
        public ?bool $renewalTermsConsented = null,
        public ?string $renewalTermsStatement = null,
        public ?CarbonImmutable $declaredAt = null,
    ) {
        if (trim($statement) === '') {
            throw new InvalidArgumentException('A customer declaration needs the wording the customer confirmed.');
        }

        if ($type === CustomerType::Business && (trim((string) $businessName) === '' || trim((string) $businessAddress) === '')) {
            throw new InvalidArgumentException('A business declares its name and its address.');
        }

        if ($type === CustomerType::Consumer && ($businessName !== null || $businessAddress !== null || $vatId !== null)) {
            throw new InvalidArgumentException('A consumer declaration names no business, address of a business or VAT number.');
        }

        if ($renewalTermsConsented !== null && trim((string) $renewalTermsStatement) === '') {
            throw new InvalidArgumentException('A consent to the renewal terms needs the wording the customer was asked to agree to.');
        }
    }

    /** A customer who buys as a consumer, in the wording they confirmed. */
    public static function consumer(
        string $statement,
        ?string $statementVersion = null,
        ?bool $renewalTermsConsented = null,
        ?string $renewalTermsStatement = null,
    ): self {
        return new self(CustomerType::Consumer, $statement, $statementVersion,
            renewalTermsConsented: $renewalTermsConsented, renewalTermsStatement: $renewalTermsStatement);
    }

    /** A customer who buys as a business, with the name and address it declared and its VAT number where it gave one. */
    public static function business(
        string $statement,
        string $businessName,
        string $businessAddress,
        ?string $vatId = null,
        ?string $statementVersion = null,
        ?bool $renewalTermsConsented = null,
        ?string $renewalTermsStatement = null,
    ): self {
        $vatId = $vatId === null || trim($vatId) === '' ? null : trim($vatId);

        return new self(CustomerType::Business, $statement, $statementVersion, trim($businessName), trim($businessAddress),
            $vatId, $renewalTermsConsented, $renewalTermsStatement);
    }
}
