<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AttachesVerifiedVatIds;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Enums\VatIdValidation;
use Stripe\StripeClient;

/**
 * Attaches a proven EU VAT number to the Stripe customer, as an `eu_vat` tax id.
 *
 * A Checkout Session for a customer who carries a tax id shows no field to enter one, even where the session asks for
 * it, and Stripe Tax reverse-charges a cross-border sale to that customer on the id it finds. The number is written as
 * the validator reads it, without spaces and in capitals, and is attached once however often it is proven.
 */
final readonly class StripeVerifiedVatIds implements AttachesVerifiedVatIds
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private VatIdValidator $validator,
    ) {}

    public function attach(Model $billable, string $vatId): VatIdValidation
    {
        $value = $this->normalized($vatId);
        $validation = $this->validator->validate($value);

        if (! $validation->isValid()) {
            return $validation;
        }

        $customerId = $this->customers->resolve($billable);

        if ($this->attached($customerId, $value) === null) {
            $this->stripe->customers->createTaxId($customerId, ['type' => 'eu_vat', 'value' => $value]);
        }

        return $validation;
    }

    public function detach(Model $billable, string $vatId): void
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return;
        }

        $taxId = $this->attached($customerId, $this->normalized($vatId));

        if ($taxId !== null) {
            $this->stripe->customers->deleteTaxId($customerId, $taxId);
        }
    }

    /** The id of the customer's EU VAT number with this value, or null where it carries none. */
    private function attached(string $customerId, string $value): ?string
    {
        foreach ($this->stripe->customers->allTaxIds($customerId, ['limit' => 100])->data as $taxId) {
            if (($taxId->type ?? null) === 'eu_vat' && ($taxId->value ?? null) === $value) {
                return $taxId->id;
            }
        }

        return null;
    }

    private function normalized(string $vatId): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $vatId));
    }
}
