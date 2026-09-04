<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Exceptions\CustomerBelongsToAnotherProvider;
use Pushery\Billing\Exceptions\MollieNotConfigured;

/**
 * The Mollie customer behind a billable — read from the owner's own row, created there on first use.
 *
 * ## The one column, and why it is the right place
 *
 * The reference is stored on `billing.customer.column` of the configured customer model. That column is
 * what `CustomerDirectory` reads to turn a webhook back into an owner, so writing anywhere else would
 * produce a customer the provider knows and this package cannot resolve — which is the same as not having
 * one, discovered later and over a webhook.
 *
 * ## Why an id from another provider is refused rather than replaced
 *
 * See {@see CustomerBelongsToAnotherProvider}. The prefix check is provider knowledge and belongs in a
 * driver: Mollie issues customer ids beginning `cst_`, and anything else in that column was issued
 * somewhere else.
 *
 * ## Identity is passed along because a blank customer is worth less than no customer
 *
 * A customer created with no name and no email shows up in the provider's dashboard as an anonymous row
 * an operator cannot match to anybody, and the provider's own fraud and dispute tooling has nothing to
 * work with. Both fields are read off the model only if they are there — the package does not require a
 * shape from a host application's user model.
 */
final readonly class MollieCustomers implements EnsuresProviderCustomer
{
    /** What a Mollie customer id starts with. */
    private const string PREFIX = 'cst_';

    /**
     * The CLIENT, not the factory that builds one — which is the house idiom and also the only shape that
     * can be exercised. `MollieClientFactory` is a `final readonly` class reading configuration, so a class
     * depending on it cannot be reached by any test: no subclass, no rebind, and no way to answer a create
     * with a known body. The sibling rails take the client for the same reason, and the container's
     * singleton is already lazy, so nothing is built earlier than it was.
     */
    public function __construct(
        private MollieApiClient $client,
        private Repository $config,
    ) {}

    public function customerFor(Model $billable): string
    {
        $column = $this->column();
        $existing = $billable->getAttribute($column);

        if (is_string($existing) && trim($existing) !== '') {
            $reference = trim($existing);

            if (! str_starts_with($reference, self::PREFIX)) {
                throw CustomerBelongsToAnotherProvider::forReference('Mollie', $reference);
            }

            return $reference;
        }

        $customer = $this->client->send(new CreateCustomerRequest(
            name: $this->stringAttribute($billable, 'name'),
            email: $this->stringAttribute($billable, 'email'),
        ));

        // Narrowed as its own step rather than inside the expression below: `send()` is declared
        // `@return mixed`, so without this the id read afterwards would be a read off anything.
        if (! $customer instanceof Customer) {
            throw MollieNotConfigured::missingApiKey();
        }

        $reference = (string) $customer->id;

        // Persisted BEFORE anything is done with it. A payment started against a customer whose reference
        // never reached the row would produce a mandate the webhook cannot resolve to anybody — the money
        // moves, the customer exists at the provider, and this package has no idea who they are.
        $billable->forceFill([$column => $reference])->save();

        return $reference;
    }

    /** The column the directory reads, so both sides always mean the same field. */
    private function column(): string
    {
        $column = $this->config->get('billing.customer.column', 'stripe_id');

        return is_string($column) && $column !== '' ? $column : 'stripe_id';
    }

    private function stringAttribute(Model $billable, string $attribute): ?string
    {
        $value = $billable->getAttribute($attribute);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
