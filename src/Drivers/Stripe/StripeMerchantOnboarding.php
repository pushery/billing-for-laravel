<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\ReportsMerchantCapabilities;
use Pushery\Billing\Contracts\ReportsOnboardingRequirements;
use Pushery\Billing\Enums\MerchantStatus;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Exceptions\MerchantRelationshipEnded;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * Stripe's receiving side: create the merchant's connected account and hand back a link into Stripe's own
 * hosted identity flow.
 *
 * `createAccount` is idempotent through the LOCAL row, not through a provider lookup. Asking Stripe "does
 * this merchant already have an account" has no answer — Stripe has no notion of our merchants — so a
 * second call without the local check creates a second account, and a merchant with two accounts has their
 * money split across two identities the provider pays separately. The unique index behind the row is the
 * backstop for the concurrent case.
 *
 * The capability flags are stored false on creation regardless of what the API returns. A brand-new
 * account occasionally comes back with a capability already true, and trusting that would let money route
 * to a merchant before the verification that the flags are supposed to represent has actually happened.
 * They are raised only by the provider's own account event.
 *
 * ## This driver stays on the v1 Connect endpoints, and that is a decision rather than an oversight
 *
 * Stripe returns a `stripe-notice` header on every account creation recommending its newer Accounts API,
 * and from SDK 20.3 the client raises that header as an `E_USER_WARNING`. It is a recommendation: the v1
 * endpoints are supported and carry no sunset date.
 *
 * Three reasons the recommendation is not followed here, in order of weight.
 *
 * The newer API is not a different spelling of the same call. Its account object nests capabilities under
 * a configuration, its onboarding links live on their own endpoint, and its account events are a separate
 * family — so adopting it means rebuilding onboarding, the capability gate and the Connect webhook mapping
 * at once. That is one change to the money path, not three small ones.
 *
 * Access to it is granted per platform account. A package that moved unilaterally would turn a log line
 * into a hard failure for every consumer whose account is not enabled for it.
 *
 * And a rebuilt onboarding path can only be proven against the provider's real test API. A faked suite
 * cannot see a response header — which is exactly how this notice was found — so shipping the rebuild on
 * a green fake would be asserting the one thing the fake cannot answer.
 *
 * The notice is deliberately NOT filtered out. Suppressing another company's deprecation signal inside a
 * library decides the consumer's log policy for them and removes the advance warning the header exists to
 * give. What the package does instead is make the failure it can cause harmless: see `creationKey()`.
 */
final readonly class StripeMerchantOnboarding implements MerchantOnboarding, ReportsMerchantCapabilities, ReportsOnboardingRequirements
{
    /**
     * The account types Stripe offers, and the ones this driver supports. The choice moves KYC duty and
     * loss liability between Stripe and the platform, and it cannot be changed for an account that has
     * already onboarded — so it is boot-time configuration with a loud failure, never a per-call argument.
     *
     * @var list<string>
     */
    public const array ACCOUNT_TYPES = ['express', 'standard'];

    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
    ) {}

    public function createAccount(Model $merchant): MerchantAccountReference
    {
        $existing = $this->row($merchant);

        if ($existing instanceof MerchantAccount) {
            // A row whose relationship has ENDED is not an onboarding to continue. Handing it back was the
            // silent half of this: no second provider account, no exception, no change, exit 0 — and an
            // operator holding a link to an account the provider no longer releases funds through, having
            // done exactly what the code told them to do. Reopening is a decision somebody makes, not a
            // side effect of asking twice.
            if ($existing->status === MerchantStatus::Terminated || $existing->deauthorized_at !== null) {
                throw MerchantRelationshipEnded::forOnboarding($existing->provider, $existing->account_reference);
            }

            return $existing->toReference();
        }

        $key = $merchant->getKey();

        $account = $this->stripe->accounts->create([
            'type' => $this->accountType(),
            'metadata' => [
                'billing_merchant_type' => $merchant->getMorphClass(),
                'billing_merchant_id' => is_scalar($key) ? (string) $key : '',
            ],
        ], ['idempotency_key' => $this->creationKey($merchant)]);

        $row = MerchantAccount::query()->create([
            'merchant_type' => $merchant->getMorphClass(),
            'merchant_id' => $key,
            'provider' => 'stripe',
            'account_reference' => (string) $account->id,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ]);

        return $row->toReference();
    }

    /**
     * The key that keeps a retry from producing a SECOND connected account.
     *
     * ## The window this closes
     *
     * Between the provider committing an account and this driver writing the row, three ordinary things can
     * intervene: a network timeout after the account already exists, a database failure on the write, and —
     * the one that made this visible — an application whose error handler turns warnings into exceptions.
     * The provider's SDK emits a `stripe-notice` response header through `trigger_error()`, so on such an
     * install the very first `createAccount()` throws AFTER the account was created.
     *
     * All three end the same way: an account at the provider that no local row names. The natural response
     * is to try again — and without a key that creates another one. Nothing looks wrong at any point.
     * Onboarding completes, and the payouts arrive split across two accounts, one of which the platform's
     * own records do not name. It is the same failure the duplicate check above exists for, arriving from
     * the other side.
     *
     * ## Why a derived key rather than a random one
     *
     * A random key protects nothing here: the retry is a NEW call, in a new process, with a new key. The
     * key has to be a function of the merchant, so that the second attempt asks the same question and the
     * provider answers with the account it already made.
     *
     * Hashed rather than spelled out because the two inputs are unbounded — a fully-qualified class name
     * and whatever a consumer uses for a primary key — while the header is not. Hashing costs the ability
     * to read the merchant out of the key, and buys a length that cannot depend on a consumer's naming.
     *
     * The provider replays a key for a limited window, so this is a retry guard, not a permanent lock. Past
     * that window the row from the first attempt is what stops a duplicate, which is the check above.
     */
    private function creationKey(Model $merchant): string
    {
        $key = $merchant->getKey();

        return 'billing-merchant-account:'.hash(
            'sha256',
            $merchant->getMorphClass().'|'.(is_scalar($key) ? (string) $key : '')
        );
    }

    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent
    {
        $account = $this->createAccount($merchant);

        $link = $this->stripe->accountLinks->create([
            'account' => $account->accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return new ClientIntent('stripe', [
            'url' => (string) $link->url,
            'account' => $account->accountId,
            // Stripe's links are single-use and short-lived. Handing the expiry across the boundary lets a
            // consumer show "this link has expired, start again" instead of a provider error page.
            'expires_at' => Carbon::createFromTimestampUTC((int) $link->expires_at)->toIso8601String(),
        ]);
    }

    /**
     * What Stripe is still waiting for on this merchant's account.
     *
     * ## A LIVE read, and it has to be
     *
     * The local row carries the three capability flags, kept current by `account.updated`. It carries no
     * requirement list, and adding one would be a copy of a list that changes every time the merchant
     * touches Stripe's form — stale within minutes and indistinguishable from current.
     *
     * ## `currently_due` and `eventually_due` are different questions
     *
     * The first is what blocks the account NOW; the second is what will block it once a threshold is
     * crossed — a volume, a date. Reporting only the first tells a merchant they are finished when they
     * are finished for today, which is the answer that produces a surprised hold later.
     *
     * `disabled_reason` is the one field that explains a capability that will not turn on however much
     * paperwork arrives, and it is why "what is missing" is not always a list of documents.
     *
     * @return array{currently_due: list<string>, eventually_due: list<string>, disabled_reason: ?string}|null
     */
    public function outstandingFor(Model $merchant): ?array
    {
        $row = $this->row($merchant);

        // A row with no reference on it is the same answer as no row: there is no account to ask about.
        // Asking anyway sends an empty id to the provider, which refuses it — and an operator sweeping every
        // merchant would get an exception rather than a skipped line, for a merchant who simply never
        // finished onboarding.
        if (! $row instanceof MerchantAccount || trim($row->account_reference) === '') {
            return null;
        }

        $account = $this->stripe->accounts->retrieve($row->account_reference);
        $requirements = $account->requirements ?? null;

        // Only the entries that ARE strings, and non-string entries are dropped rather than coerced. A
        // requirement key is a string in every version of this API; something else in that position means
        // the shape changed, and printing `Array` or `1` as a thing a merchant must go and do is worse
        // than printing one item fewer.
        $list = static function (mixed $value): array {
            $items = match (true) {
                is_array($value) => $value,
                $value instanceof StripeObject => $value->toArray(),
                default => [],
            };

            return array_values(array_filter($items, is_string(...)));
        };

        $due = $requirements === null ? null : ($requirements['currently_due'] ?? null);
        $eventually = $requirements === null ? null : ($requirements['eventually_due'] ?? null);
        $reason = $requirements === null ? null : ($requirements['disabled_reason'] ?? null);

        return [
            'currently_due' => $list($due),
            'eventually_due' => $list($eventually),
            'disabled_reason' => is_string($reason) && $reason !== '' ? $reason : null,
        ];
    }

    /**
     * What the provider says about this merchant's account right now.
     *
     * The same `accounts->retrieve()` the requirements report already makes, reading the other half of the
     * answer. It builds a REFERENCE and writes nothing: the row is updated by the one writer both this and
     * the webhook go through, so "only a provider report lifts a flag" stays a single rule in a single
     * place.
     *
     * The local `deauthorized_at` is carried onto the reference deliberately. The provider does not know
     * the merchant disconnected from THIS platform — it will happily report every flag true — and dropping
     * the field here would make a refresh the one way to make a disconnected merchant receivable again.
     */
    public function capabilitiesFor(Model $merchant): ?MerchantAccountReference
    {
        $row = $this->row($merchant);

        // A row with no reference on it is the same answer as no row: there is nothing to ask about. Asking
        // anyway sends an empty id to the provider, which refuses it — and an operator sweeping every
        // merchant would get an exception instead of a skipped line, for somebody who simply never finished
        // onboarding.
        if (! $row instanceof MerchantAccount || trim($row->account_reference) === '') {
            return null;
        }

        $account = $this->stripe->accounts->retrieve($row->account_reference);

        return new MerchantAccountReference(
            'stripe',
            $row->account_reference,
            (bool) ($account->charges_enabled ?? false),
            (bool) ($account->payouts_enabled ?? false),
            (bool) ($account->details_submitted ?? false),
            $row->deauthorized_at,
        );
    }

    private function row(Model $merchant): ?MerchantAccount
    {
        return MerchantAccount::query()
            ->where('provider', 'stripe')
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->first();
    }

    /** The configured account type, refused loudly when it is one this driver does not support. */
    private function accountType(): string
    {
        $type = $this->config->get('billing.marketplace.onboarding.account_type', 'express');

        if (! is_string($type) || ! in_array($type, self::ACCOUNT_TYPES, true)) {
            throw InvalidBillingConfig::unsupportedMerchantAccountType(
                is_string($type) ? $type : gettype($type),
                self::ACCOUNT_TYPES,
            );
        }

        return $type;
    }
}
