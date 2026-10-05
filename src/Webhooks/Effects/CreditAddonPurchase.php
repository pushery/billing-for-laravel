<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Jobs\PushCreditToProvider;
use Pushery\Billing\Marketplace\CreditTopUpVolume;
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
        private BillingEventLog $log,
        /**
         * The bound catalog, not the configured one: an application that serves add-ons from a catalog of its own
         * grants their units and classifies their credit there, and the configured catalog would know none of them.
         */
        private AddonCatalog $addons,
        private PrepaidLedger $prepaid,
        /** The register of works, resolved from the container when the caller supplied none. */
        private ?AddonContentMap $content = null,
    ) {}

    /** Whether this purchase lands on the buyer's balance: money credit by its catalog, and never a work. */
    private function creditsMoney(string $addonKey): bool
    {
        return CreditTopUpVolume::creditsMoney($this->addons, $this->content ?? Container::getInstance()->make(AddonContentMap::class), $addonKey);
    }

    public function __invoke(AddonPurchased $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        // An add-on grants EITHER usage units or money credit — never both. Paying one purchase out twice
        // is not generosity, it is a bug. And it may grant neither: a work, a post or a sponsorship is paid for
        // and handed over elsewhere, and crediting its price back would give the buyer both.
        $grant = $this->addons->grantsFor($event->addonKey);
        $credit = ! $grant instanceof UnitGrant && $this->creditsMoney($event->addonKey);

        DB::transaction(function () use ($event, $owner, $grant, $credit): void {
            if (! $this->purchases->recordOnce($owner, $event->reference, $event->addonKey, $event->amount, $event->paymentReference, $event->declarationReference, $credit, $grant)) {
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

            // Neither units nor credit: the purchase is recorded, and what it bought is handed over by whoever
            // sells it, the register of works for a work or the application for a sale of its own.
            if (! $credit) {
                $this->log->record('addon.purchased', $owner, [
                    'addon' => $event->addonKey,
                    'amount' => $event->amount->minorUnits,
                    'currency' => $event->amount->currency,
                    'reference' => $event->reference,
                ], AuditSource::Webhook);

                return;
            }

            $this->ledger->credit($owner, $event->amount, CreditReason::AddonTopup);

            $this->log->record('addon.credited', $owner, [
                'addon' => $event->addonKey,
                'amount' => $event->amount->minorUnits,
                'currency' => $event->amount->currency,
                'reference' => $event->reference,
            ], AuditSource::Webhook);
        });

        // Only money credit touched the money balance, so only money credit is mirrored onto the provider's.
        if (! $credit) {
            return;
        }

        // Mirror the credit onto the provider balance so it reduces the customer's next invoice, AFTER the
        // local credit commits: the job is queued once this effect's run has committed, so neither the run nor
        // the balance row it locked waits on the provider, and a run that rolls back pushes nothing. Deliberately
        // UNCONDITIONAL: if the local claim succeeded but an earlier push failed, another delivery must still get
        // the provider in step — so it is idempotent by the purchase reference, and the provider dedups the key
        // rather than double-crediting.
        Bus::dispatch(new PushCreditToProvider($owner, $event->amount, 'addon:'.$event->reference));
    }
}
