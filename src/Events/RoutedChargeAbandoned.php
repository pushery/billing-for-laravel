<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * A routed payment that could not complete at once will not complete.
 *
 * ## Why a routed charge is ever pending
 *
 * Most routed payments settle in the same breath they are made — on a destination charge the transfer
 * exists the moment the charge does. Two do not: a card that demands 3-D Secure, and a bank debit that
 * clears days later. Both return successfully and immediately, having moved no money, and the package
 * writes the merchant's row as `pending` rather than pretending otherwise.
 *
 * ## What was missing, and what it cost
 *
 * Nothing moved that row afterwards. `RoutedChargeLedger::settle()` had exactly one caller, the synchronous
 * path, and `fail()` had none — so a charge that went pending stayed pending for good.
 *
 * That is not a cosmetic state. Three bound readers count only settled rows, and one of them is the
 * small-business turnover threshold: a merchant paid entirely by bank debit would read as having earned
 * nothing, indefinitely, and a threshold nobody crosses is a tax decision made by an omission.
 *
 * ## Only from pending, and that is a real distinction
 *
 * A charge that settled and later goes wrong is a refund or a dispute, never a failure. Treating it as one
 * would erase the fact that the money was, for a while, genuinely there — which is the difference between
 * a merchant who was never paid and one who was paid and then charged back.
 *
 * ## Neutral on purpose
 *
 * It names the provider and the provider's own reference for the payment, not a Stripe object. Whoever
 * settles matches that reference against the row this package wrote when the payment was made.
 */
final readonly class RoutedChargeAbandoned implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        /** The provider's id for the payment — the same value recorded when the charge was made. */
        public string $paymentReference,
    ) {}
}
