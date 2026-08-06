<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use Pushery\Billing\Contracts\MarketplaceRails;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * A recording stand-in for the receiving side, so a marketplace consumer can assert routing without a
 * provider.
 *
 * It is bound EXPLICITLY, never by the general billing fake. That fake stands in for the three seams that
 * move a buyer's money; the receiving side is a different surface with different assertions, and binding it
 * along would quietly replace a consumer's own rails in tests that never asked for it.
 *
 * Accounts it hands out are NOT receivable. A fake that reported a fully capable account would let a test
 * route money the moment onboarding started — the exact sequence the real gate refuses, since a provider
 * confirms capabilities later, or never. A test that wants a receivable merchant says so.
 */
final class FakeMarketplaceRails implements MarketplaceRails, MerchantAccountDirectory, MerchantOnboarding
{
    /** @var array<string, MerchantAccountReference> */
    private array $accounts = [];

    /** @var array<string, Model> */
    private array $merchants = [];

    /** @var list<array{merchant: Model, refresh: string, return: string}> */
    private array $onboardings = [];

    public function onboarding(): MerchantOnboarding
    {
        return $this;
    }

    public function accounts(): MerchantAccountDirectory
    {
        return $this;
    }

    /** Give a merchant an account that the provider has fully confirmed. */
    public function markReceivable(Model $merchant, string $accountId = 'acct_fake'): self
    {
        return $this->remember($merchant, new MerchantAccountReference(
            provider: 'fake',
            accountId: $accountId,
            chargesEnabled: true,
            payoutsEnabled: true,
            detailsSubmitted: true,
        ));
    }

    /** Give a merchant an account the provider has confirmed nothing about. */
    public function markOnboarding(Model $merchant, string $accountId = 'acct_fake'): self
    {
        return $this->remember($merchant, new MerchantAccountReference(provider: 'fake', accountId: $accountId));
    }

    public function createAccount(Model $merchant): MerchantAccountReference
    {
        $existing = $this->accountFor($merchant);

        if ($existing instanceof MerchantAccountReference) {
            return $existing;
        }

        $this->markOnboarding($merchant, 'acct_'.count($this->accounts));

        return $this->accountFor($merchant) ?? new MerchantAccountReference('fake', 'acct_fake');
    }

    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent
    {
        $this->createAccount($merchant);
        $this->onboardings[] = ['merchant' => $merchant, 'refresh' => $refreshUrl, 'return' => $returnUrl];

        return new ClientIntent('fake', ['url' => 'https://billing.test/fake-onboarding']);
    }

    public function merchantForReference(string $accountReference): ?Model
    {
        return $this->merchants[$accountReference] ?? null;
    }

    public function accountFor(Model $merchant): ?MerchantAccountReference
    {
        return $this->accounts[$this->key($merchant)] ?? null;
    }

    public function assertOnboardingStarted(Model $merchant): void
    {
        $found = false;

        foreach ($this->onboardings as $call) {
            if ($this->key($call['merchant']) === $this->key($merchant)) {
                $found = true;
            }
        }

        PHPUnit::assertTrue($found, 'Expected merchant onboarding to have started, but it did not.');
    }

    private function remember(Model $merchant, MerchantAccountReference $account): self
    {
        $this->accounts[$this->key($merchant)] = $account;
        $this->merchants[$account->accountId] = $merchant;

        return $this;
    }

    private function key(Model $merchant): string
    {
        return $merchant->getMorphClass().':'.(is_scalar($merchant->getKey()) ? (string) $merchant->getKey() : '');
    }
}
