<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Consumer\CustomerTypes;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Enums\CustomerType;

/**
 * The default: an owner is a business when the application sells only to businesses, or when the owner declared
 * itself one.
 *
 * Without a declaration no owner is a business, which is where {@see NoBusinessBuyers} left every owner. An offer only
 * businesses may buy is then shown to the owners that declared themselves one at checkout, and to everyone in an
 * application that sells only to businesses. An application that knows its company accounts binds its own.
 */
final readonly class DeclaredBusinessBuyers implements IdentifiesBusinessBuyers
{
    public function __construct(private CustomerTypes $types) {}

    public function isBusiness(Model $owner): bool
    {
        return $this->types->of($owner) === CustomerType::Business;
    }
}
