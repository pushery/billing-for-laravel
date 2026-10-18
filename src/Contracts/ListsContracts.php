<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Consumer\RunningSubscriptions;
use Pushery\Billing\ValueObjects\ContractChoice;

/**
 * Which contracts a person has, for the cancellation button and the withdrawal function to offer.
 *
 * The package binds {@see RunningSubscriptions}, which offers every subscription of the
 * person that is running and not already ending, named after its tier. Bind your own to name contracts the way your
 * product does, to leave some out, or to state the initial term a contract was sold with.
 */
interface ListsContracts
{
    /**
     * The contracts this person can declare a cancellation or a withdrawal for, in the order a page lists them.
     *
     * @return list<ContractChoice>
     */
    public function contractsOf(Model $owner): array;
}
