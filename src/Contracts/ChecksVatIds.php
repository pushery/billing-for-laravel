<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use InvalidArgumentException;
use Pushery\Billing\ValueObjects\VatIdCheck;

/**
 * Asks the VAT register about a VAT identification number and keeps what it answered: the verdict and, where the
 * register supplies them, the name and address it holds for the number, the moment it answered, and for a
 * qualified request the identifier it gave that request. The verdict alone says yes; the record is what a check
 * before a zero-rated intra-community supply can be shown with afterwards.
 *
 * The verdict follows {@see VatIdValidator}: a register that cannot answer is Unavailable, never Invalid. Every
 * binding of that contract answers this one as well, one that does not read the register with its verdict alone.
 */
interface ChecksVatIds
{
    /**
     * @param  string|null  $requesterVatId  your own VAT id, which makes the request qualified: the register gives
     *                                       it an identifier and keeps it on record
     *
     * @throws InvalidArgumentException when a requester is given that is not a VAT id
     */
    public function check(?string $vatId, ?string $requesterVatId = null): VatIdCheck;
}
