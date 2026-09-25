<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Events\CreatorTaxStatusChanged;
use Pushery\Billing\Marketplace\SettlementRestater;

/**
 * Queue the settlements a corrected standing has made wrong.
 *
 * Only queued here. This runs inside whatever recorded the standing — a creator's form, an import, a registry
 * check — and issuing documents there would make that write as slow as the creator's history is long, and
 * would fail it with every refusal a replacement can meet. `billing:settlements:restate` issues them.
 *
 * Every source counts, a creator's own declaration as much as an automatic change: what makes a settlement
 * wrong is the standing in force at its supply date, not who recorded it.
 */
final readonly class QueueSettlementRestatements
{
    public function __construct(
        private SettlementRestater $restater,
        private Repository $config,
    ) {}

    public function handle(CreatorTaxStatusChanged $event): void
    {
        // Without the marketplace there are no settlements, and nothing here may touch the database.
        if ($this->config->get('billing.marketplace.enabled', false) !== true) {
            return;
        }

        $this->restater->queue($event->merchant, $event->effectiveFrom);
    }
}
