<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;
use Pushery\Billing\Events\ChargebackReversed;
use Pushery\Billing\Events\RefundFailed;

/**
 * Read back what became of refunds and chargebacks where the provider's webhook does not say.
 *
 * A refund that failed or was canceled, and a chargeback the bank reversed. A driver whose webhook announces both binds
 * no reader. One whose webhook does not is read by `billing:money-back:reconcile`, which records each outcome once.
 */
interface ReportsMoneyBackOutcomes
{
    /**
     * Each refund created since `$refundsSince` that failed or was canceled, and each chargeback created since
     * `$chargebacksSince` that was reversed.
     *
     * Every one the provider still lists, whether or not it was reported before: the reconciliation keeps track of
     * what it recorded.
     *
     * @return iterable<ChargebackReversed|RefundFailed>
     */
    public function outcomesSince(CarbonImmutable $refundsSince, CarbonImmutable $chargebacksSince): iterable;
}
