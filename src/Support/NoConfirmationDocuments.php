<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\AttachesToContractConfirmation;
use Pushery\Billing\ValueObjects\ContractConfirmation;

/**
 * The default: the confirmation of a contract carries no attachment until the application names one.
 */
final class NoConfirmationDocuments implements AttachesToContractConfirmation
{
    public function documentsFor(ContractConfirmation $contract, object $owner): iterable
    {
        return [];
    }
}
