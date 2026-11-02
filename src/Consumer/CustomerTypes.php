<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Enums\CustomerAudience;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Models\CustomerDeclaration;
use Pushery\Billing\Support\DeclaredBusinessBuyers;

/**
 * Whether a customer buys as a consumer or as a business, read in one place.
 *
 * In this order, and the first that answers decides:
 *
 * 1. An application that sells only to businesses treats every customer as one.
 * 2. The latest declaration of the customer, which is what they said themselves at checkout.
 * 3. An `IdentifiesBusinessBuyers` the application bound, which knows its company accounts.
 * 4. Otherwise a consumer: a customer whose type nobody established keeps the rights a consumer has.
 *
 * The package's own binding of that contract, {@see DeclaredBusinessBuyers}, reads its answer here, so the third step
 * passes it over rather than asking it back.
 */
final readonly class CustomerTypes
{
    public function __construct(
        private Repository $config,
        private CustomerDeclarations $declarations,
    ) {}

    public function of(Model $owner): CustomerType
    {
        if (CustomerAudience::configured($this->config) === CustomerAudience::BusinessOnly) {
            return CustomerType::Business;
        }

        $declared = $this->declarations->current($owner);

        if ($declared instanceof CustomerDeclaration) {
            return $declared->customer_type;
        }

        $businesses = $this->businessBuyers();

        if (! $businesses instanceof DeclaredBusinessBuyers && $businesses->isBusiness($owner)) {
            return CustomerType::Business;
        }

        return CustomerType::Consumer;
    }

    /** The identification of business buyers bound, typed as the contract: an application may bind its own. */
    private function businessBuyers(): IdentifiesBusinessBuyers
    {
        return Container::getInstance()->make(IdentifiesBusinessBuyers::class);
    }
}
