<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Exceptions\InconsistentChargeRoutingForPosture;

/**
 * Refuses a routing whose money flow contradicts the seller the platform has declared itself to be.
 *
 * The charge type and the posture answer different questions — who the provider treats as the merchant of
 * record, and who the documents name as the seller — and neither answer determines the other. For an
 * electronic service the law assigns the seller whatever the provider does with the money. That
 * independence is correct and is exactly why this guard has to exist: two axes that cannot be derived from
 * each other can be set to disagree, and the result is not an exception anybody sees. It is a receipt and a
 * settlement describing different transactions, and it surfaces in an audit.
 *
 * It runs when a transaction is resolved, BEFORE any money moves. A guard at document time would be
 * refusing after the payment had already gone the wrong way.
 *
 * The table lives in configuration because the reading is a legal one and jurisdictions differ; the guard
 * itself compares two enum values and knows no statute.
 */
final readonly class ChargeRoutingConsistencyGuard
{
    public function __construct(private Repository $config) {}

    /** @throws InconsistentChargeRoutingForPosture */
    public function assertCompatible(ChargeType $type, SellerOfRecordPosture $posture): void
    {
        $permitted = $this->permittedFor($type);

        if (! in_array($posture->value, $permitted, true)) {
            throw InconsistentChargeRoutingForPosture::forPair($type, $posture, $permitted);
        }
    }

    /** Whether a pair holds, for a caller that wants to choose rather than be refused. */
    public function permits(ChargeType $type, SellerOfRecordPosture $posture): bool
    {
        return in_array($posture->value, $this->permittedFor($type), true);
    }

    /**
     * The postures a charge type may be used with.
     *
     * An unreadable or missing entry permits NOTHING rather than everything. A typo in this table would
     * otherwise open the one combination it exists to close, and it would do so silently — the guard would
     * still run, still pass, and still be reported as protecting the line.
     *
     * @return list<string>
     */
    private function permittedFor(ChargeType $type): array
    {
        $table = $this->config->get('billing.marketplace.charge_type_by_posture', []);

        if (! is_array($table)) {
            return [];
        }

        $permitted = $table[$type->value] ?? null;

        if (! is_array($permitted)) {
            return [];
        }

        return array_values(array_filter($permitted, is_string(...)));
    }
}
