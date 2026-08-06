<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * What the platform keeps of a sale routed to a given merchant.
 *
 * A contract rather than a config read at the call site, because a commission is a commercial arrangement
 * and arrangements differ per merchant: an introductory rate, a negotiated one, a promotional zero. The
 * package ships the flat configured answer and gets out of the way of a consumer who has agreements.
 *
 * It takes the merchant and not the sale on purpose. A rate that could vary per transaction — by amount,
 * by product, by date — is one a merchant cannot be told in advance, and a commission nobody can state
 * before the sale is not a commission but a deduction.
 */
interface PlatformFeeResolver
{
    public function feeFor(Model $merchant): PlatformFee;
}
