<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ConfirmationDocument;
use Pushery\Billing\ValueObjects\ContractConfirmation;

/**
 * The documents an operator attaches to the confirmation of a contract, a subscription as it begins or the purchase
 * of an add-on.
 *
 * The confirmation states the contract and renders the operator's view in its text. A document that belongs beside
 * the text rather than in it, the terms in the version the buyer accepted for one, is attached here: the mail then
 * carries the content of the contract on a durable medium without the whole terms in its body. None until the
 * application binds an answer of its own.
 */
interface AttachesToContractConfirmation
{
    /**
     * The documents to attach to the confirmation of one contract, in the order the mail lists them.
     *
     * @return iterable<ConfirmationDocument>
     */
    public function documentsFor(ContractConfirmation $contract, object $owner): iterable;
}
