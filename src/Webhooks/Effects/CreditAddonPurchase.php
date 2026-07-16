<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Support\AddonPurchases;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\PrepaidLedger;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * Credits an owner's balance for a paid one-time add-on, exactly once per purchase. The purchase row
 * is claimed by its reference and the credit applied in the same transaction (at-least-once): the
 * unique reference makes the claim the dedup, so a redelivered webhook records nothing and credits
 * nothing, while a mid-effect failure rolls the claim back so the provider's retry re-applies it.
 */
final readonly class CreditAddonPurchase
{
    public function __construct(
        private CustomerDirectory $directory,
        private AddonPurchases $purchases,
        private CreditLedger $ledger,
        private CreditSync $creditSync,
        private BillingEventLog $log,
        private ConfigAddonCatalog $addons,
        private PrepaidLedger $prepaid,
    ) {}

    public function __invoke(AddonPurchased $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        // An add-on grants EITHER usage units or money credit — never both. Paying one purchase out twice
        // is not generosity, it is a bug.
        $grant = $this->addons->grantsFor($event->addonKey);

        DB::transaction(function () use ($event, $owner, $grant): void {
            if (! $this->purchases->recordOnce($owner, $event->reference, $event->addonKey, $event->amount, $event->paymentReference)) {
                return;
            }

            if ($grant instanceof UnitGrant) {
                $this->prepaid->grant($owner, $grant->meterKey, $grant->units);

                $this->log->record('addon.units_granted', $owner, [
                    'addon' => $event->addonKey,
                    'meter' => $grant->meterKey,
                    'units' => $grant->units,
                    'reference' => $event->reference,
                ], AuditSource::Webhook);

                return;
            }

            $this->ledger->credit($owner, $event->amount);

            $this->log->record('addon.credited', $owner, [
                'addon' => $event->addonKey,
                'amount' => $event->amount->minorUnits,
                'currency' => $event->amount->currency,
                'reference' => $event->reference,
            ], AuditSource::Webhook);
        });

        // A units add-on never touched the money balance, so there is nothing to mirror onto the provider's.
        if ($grant instanceof UnitGrant) {
            return;
        }

        // Mirror the credit onto the provider balance so it reduces the customer's next invoice, AFTER the
        // local credit commits. Deliberately UNCONDITIONAL: if the local claim succeeded but this push
        // failed (a network blip), the retry must still get the provider in step — so it is idempotent by
        // the purchase reference, and the provider dedups the key rather than double-crediting.
        $this->creditSync->push($owner, $event->amount, 'addon:'.$event->reference);
    }
}
