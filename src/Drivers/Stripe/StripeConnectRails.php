<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\MarketplaceRails;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Marketplace\DatabaseMerchantAccountDirectory;
use Stripe\StripeClient;

/**
 * The Stripe Connect rails — the marketplace's two verbs, assembled.
 *
 * ## What was missing, and it was not the parts
 *
 * Both halves already shipped. `StripeMerchantOnboarding` creates connected accounts and hosted onboarding
 * links; `DatabaseMerchantAccountDirectory` resolves a merchant to its account and back. What did not exist
 * was anything that put them together and said "these are the marketplace rails" — so the ONLY
 * implementation of {@see MarketplaceRails} in the package was `FakeMarketplaceRails`, a test double.
 *
 * The consequence was not a subtle one. `BillingManager::marketplaceRails()` refuses any driver that is not
 * a {@see RoutesMoney}, and the shipped Stripe driver was not one — so on the
 * only driver this package ships, that call could only ever throw. Every marketplace capability behind it
 * was unreachable, and the go-live checkpoint said so in as many words:
 *
 * > The active billing driver [stripe] does not route money to merchants.
 *
 * That refusal was correct and load-bearing while it was true. It is this class that makes it false.
 *
 * ## Why the account directory is built here rather than resolved
 *
 * It is keyed on the PROVIDER, and the provider is this driver's own name. Resolving a shared binding would
 * hand back whichever provider was registered last — which, on an install that registers two drivers, is a
 * merchant lookup that quietly answers for the wrong one. Constructed here, the key cannot be anything else.
 *
 * ## What is deliberately NOT on these rails
 *
 * The refund reversal. The obvious reading of "marketplace rails" would put `refundWithReversal()` and
 * `reverseTransfer()` here, and this package already answers both — through {@see MovesMerchantShare} and
 * {@see ReversesMerchantShare}, which `StripeMerchantTransfers` implements and which `RoutedPayment` and
 * `BillingAdmin` actually call.
 *
 * Adding them here as well would put a SECOND path to the same money beside the first. Two ways to reverse
 * a transfer is two places that decide capping, idempotency and rounding, and they drift — which for a
 * clawback means a merchant is either short-changed or paid twice, in silence. The narrower contracts stay
 * the single answer; these rails carry the two verbs that have no other home.
 */
final readonly class StripeConnectRails implements MarketplaceRails
{
    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
        private string $provider,
    ) {}

    public function onboarding(): MerchantOnboarding
    {
        return new StripeMerchantOnboarding($this->stripe, $this->config);
    }

    public function accounts(): MerchantAccountDirectory
    {
        return new DatabaseMerchantAccountDirectory($this->provider);
    }
}
