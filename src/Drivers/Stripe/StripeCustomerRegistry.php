<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerRegistry;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * The one place a billable is mapped to its Stripe customer — read it, or create it with identity and
 * persist the reference. Every money surface needs the customer id, and each had grown its own private
 * copy of "read stripe_id, else create([])"; a create with no email or name gives Stripe nothing to put
 * on the invoice or the receipt, and a hardcoded column ignores a consumer that renamed it.
 *
 * The reference column is config('billing.customer.column') (Cashier's `stripe_id` by default), honoured
 * on both the read and the write.
 */
final readonly class StripeCustomerRegistry implements CustomerRegistry
{
    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
    ) {}

    /** The billable's stored Stripe customer reference, or null when it has none yet. */
    public function find(Model $billable): ?string
    {
        $id = $billable->getAttribute($this->column());

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The billable's Stripe customer reference, creating the customer when there is none. A new customer
     * is stamped with the billable's email and name (so the invoice and the receipt are not anonymous)
     * and a back-reference to the owner, and its id is persisted on the reference column.
     */
    public function resolve(Model $billable): string
    {
        $existing = $this->find($billable);

        if ($existing !== null) {
            return $existing;
        }

        $key = $billable->getKey();

        $customer = $this->stripe->customers->create(array_filter([
            'email' => $this->attribute($billable, 'email'),
            'name' => $this->attribute($billable, 'name'),
            'metadata' => [
                'billing_owner_type' => $billable->getMorphClass(),
                'billing_owner_id' => is_scalar($key) ? (string) $key : '',
            ],
        ]));

        $billable->forceFill([$this->column() => $customer->id])->save();

        return $customer->id;
    }

    /**
     * Delete the owner's Stripe customer and forget its reference.
     *
     * Irreversible, and it cancels that customer's live subscriptions at Stripe — which is why nothing does
     * this unless the app turned it on. A customer Stripe has already deleted is not an error: the point is
     * that it is gone. Stripe keeps its own invoice and charge records either way; it is the customer object
     * that goes, not the accounting behind it.
     */
    public function forget(Model $billable): void
    {
        $customerId = $this->find($billable);

        if ($customerId === null) {
            return;
        }

        try {
            $this->stripe->customers->delete($customerId);
        } catch (InvalidRequestException) {
            // Already gone at Stripe. The local reference still has to go.
        }

        $billable->forceFill([$this->column() => null])->save();
    }

    private function column(): string
    {
        $column = $this->config->get('billing.customer.column', 'stripe_id');

        return is_string($column) && $column !== '' ? $column : 'stripe_id';
    }

    /** A non-empty string attribute of the billable, or null (so array_filter drops it from the payload). */
    private function attribute(Model $billable, string $key): ?string
    {
        $value = $billable->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
