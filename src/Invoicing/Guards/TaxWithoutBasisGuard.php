<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

use Illuminate\Container\Container;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\BillingManager;
use RuntimeException;
use Throwable;

/**
 * Refuses a document that states a tax nobody was in a position to determine.
 *
 * ## The gap is a capability difference, not a missing column
 *
 * The invoice table serves two document worlds. A subscription invoice from a provider that determines tax
 * itself stores a RESULT — `tax_minor` and nothing about how it was reached — and that is correct: the
 * package did not decide it, the provider did, and `supportsProviderTax: true` says so.
 *
 * A driver whose provider determines nothing has no such result to store. Neither it nor the package has
 * established a place of supply, an archetype, a rate band or an exemption — so a `tax_minor` written there
 * is a claim with no basis at all, and zero is not the absence of a claim. It is the claim that no tax was
 * due.
 *
 * ## Why it cannot be seen
 *
 * Every tax column is nullable, so the schema cannot tell the two apart, and neither can a reader. A
 * document with a tax and no basis is indistinguishable from a complete one: no column is empty, the
 * totals add up, and it looks finished. That is the most expensive shape a defect can take on a numbered,
 * immutable tax document — it does not announce its own incompleteness.
 *
 * ## Read from the capability, never from a list of drivers
 *
 * A maintained list would be right today and wrong at the third driver, and wrong in the silent direction:
 * a new local-engine driver absent from the list would write exactly the document this refuses. The
 * question is asked of the driver itself, which is the only place that answers it truthfully.
 *
 * ## Only a driver this install actually has
 *
 * A provider name that resolves to no registered driver is out of scope, exactly like a document with no
 * provider at all. This guard compares what a driver DOES against what a document CLAIMS, and it can do
 * neither for a name it has never heard of — a marketplace routing through a PSP that is not a billing
 * driver is an ordinary shape, and refusing its receipts would be this rule making a statement about
 * something it cannot see.
 *
 * That boundary was measured rather than chosen: the first version treated an unresolvable name as
 * "determines nothing" and refused six documents across five suites, none of them the shape this exists
 * for. The safe-direction argument holds for a driver that is REGISTERED and says it determines no tax;
 * it does not extend to a name that answers nothing at all.
 */
final class TaxWithoutBasisGuard
{
    /**
     * The columns that record HOW a tax was determined.
     *
     * One of them is enough. They are alternatives rather than a checklist — a reverse-charge supply names
     * a place of supply and no rate, an exempt one names its reason and no band — so demanding all of them
     * would refuse correct documents, and demanding none would be no guard at all.
     */
    public const array DETERMINATION_COLUMNS = [
        'tax_archetype',
        'place_of_supply_rule',
        'tax_rate_category',
        'tax_rate_bps',
        'tax_exemption_reason',
        'supply_regime',
        'taxation_basis',
    ];

    /** @throws RuntimeException when a tax figure is stated with nothing behind it */
    public function assertHasBasis(InvoiceRecord $invoice): void
    {
        if ($invoice->tax_minor === null) {
            return;
        }

        $provider = $invoice->provider;

        // NO DRIVER, NO STATEMENT ABOUT ONE. `provider` is nullable, and a document that names none is not
        // making a claim this guard can judge: the gap it exists for is a capability DIFFERENCE between
        // drivers, and there is no driver here to differ. Whether a package-issued document with no
        // provider must carry its own basis is a real question, and a separate one — answering it here
        // would be a second rule smuggled in under the first, and it would refuse documents this ticket
        // never looked at.
        if ($provider === null || $provider === '') {
            return;
        }

        if ($this->providerDeterminesTaxOrIsUnknown($provider)) {
            return;
        }

        foreach (self::DETERMINATION_COLUMNS as $column) {
            if ($invoice->getAttribute($column) !== null) {
                return;
            }
        }

        throw new RuntimeException(
            "This document states tax_minor={$invoice->tax_minor}, but its driver [{$invoice->provider}] does "
            .'not determine tax and the document records no basis of its own — no archetype, place of supply, '
            .'rate band, exemption reason, regime or taxation basis. A tax figure nobody established is not a '
            .'smaller claim than a wrong one: it is a numbered, immutable document asserting an amount that '
            .'cannot be defended. Leave tax_minor null until the determination exists, because null says '
            .'"not established" and zero says "none was due".'
        );
    }

    /**
     * Whether this document's driver determines tax — or is not a driver this install has at all.
     *
     * Both answers mean the same thing here: there is nothing to compare. Resolved through the manager,
     * which is how every other consumer reaches a driver.
     */
    private function providerDeterminesTaxOrIsUnknown(string $provider): bool
    {
        try {
            return Container::getInstance()->make(BillingManager::class)
                ->driver($provider)
                ->capabilities()
                ->supportsProviderTax;
        } catch (Throwable) {
            return true; // not a driver we have — see the class docblock
        }
    }
}
