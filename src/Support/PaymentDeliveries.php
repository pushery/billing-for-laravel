<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Contracts\NamesThePayment;
use Pushery\Billing\Contracts\PaymentAccounts;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Exceptions\UnsupportedDriver;
use Pushery\Billing\Models\BillingWebhookEvent;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\Webhooks\StoredDeliveryRequest;

/**
 * The deliveries to a payment account, found by the payment each of them is about or by the account alone.
 *
 * A delivery to a payment account has no owner the package knows: the person who paid is the application's customer.
 * So the erasure and the export of a person, which look for an owner, never reach these deliveries, and an application
 * that knows which payments were the person's asks for those instead. The eraser and the export read the deliveries
 * here, so the two cannot come to disagree about which ones belong to a payment.
 *
 * The receiver names the payment of each delivery as it records it. A delivery an earlier version recorded is named
 * here, from its stored payload and by the account's own mapper, the first time the account's deliveries are asked for
 * by payment. Where the application no longer knows the account, its mapper cannot be built and those stay unnamed;
 * removing the account's deliveries altogether reaches them.
 *
 * @internal
 */
final readonly class PaymentDeliveries
{
    private const string TABLE = 'billing_webhook_events';

    /** How many deliveries are read and named at a time. */
    private const int CHUNK = 200;

    /**
     * The deliveries to the account that are about one of the payments.
     *
     * @param  list<string>  $payments  the provider's ids of the payments, as `AccountPaymentChanged` reported them
     *
     * @throws InvalidArgumentException when the account is not a payment account's key
     */
    public function of(string $provider, string $account, array $payments): Builder
    {
        $this->assertAccount($account);
        $this->nameEarlierDeliveries($provider, $account);

        $payments = array_values(array_unique(array_filter(
            array_map(trim(...), $payments),
            static fn (string $payment): bool => $payment !== '',
        )));

        return $this->ofAccount($provider, $account)->whereIn('payment_reference', $payments);
    }

    /**
     * Every delivery to the account.
     *
     * @throws InvalidArgumentException when the account is not a payment account's key
     */
    public function ofAccount(string $provider, string $account): Builder
    {
        $this->assertAccount($account);

        return DB::table(self::TABLE)->where('provider', $provider)->where('account_reference', $account);
    }

    /**
     * Refuse a reference that is no payment account's key.
     *
     * The platform's own deliveries are recorded without an account, and a merchant of the marketplace under the
     * provider's id for it. Neither belongs to a payment account, and an empty reference would select every delivery to
     * the platform.
     */
    private function assertAccount(string $account): void
    {
        if (! PaymentAccount::isKey($account)) {
            throw new InvalidArgumentException("`{$account}` is no payment account's key: a key is lowercase letters, digits and inner hyphens, as in the account's webhook address. The platform's own deliveries and a marketplace merchant's are not an account's to reach this way.");
        }
    }

    /**
     * Name the payment of each delivery to the account that an earlier version recorded without one.
     *
     * Only a delivery that still holds its payload can be named, and only one that has no name yet is read, so after
     * the first question this reads the few deliveries that name no payment at all. The payloads go with the retention
     * clock, and those few with them.
     */
    private function nameEarlierDeliveries(string $provider, string $account): void
    {
        $mapper = $this->mapperOf($provider, $account);

        if (! $mapper instanceof NamesThePayment) {
            return;
        }

        $ledger = new WebhookEventLedger;

        BillingWebhookEvent::model()::query()
            ->where('provider', $provider)
            ->where('account_reference', $account)
            ->whereNull('payment_reference')
            ->whereNotNull('payload')
            ->chunkById(self::CHUNK, static function (Collection $deliveries) use ($mapper, $ledger): void {
                foreach ($deliveries as $delivery) {
                    $ledger->namePayment($delivery, $mapper->paymentReference(StoredDeliveryRequest::of($delivery)));
                }
            });
    }

    /** The mapper of the account the application knows under this provider and key, or null where it knows none. */
    private function mapperOf(string $provider, string $account): ?WebhookEventMapper
    {
        // Asked when the question comes rather than held from construction, so an eraser the application keeps for longer
        // still reads the accounts it knows now.
        $found = Container::getInstance()->make(PaymentAccounts::class)->find($provider, $account);

        // The application's lookup answers by the pair it was asked for, and an account of another provider or under
        // another key is not the one the deliveries were recorded under.
        if (! $found instanceof PaymentAccount || $found->provider !== $provider || $found->key !== $account) {
            return null;
        }

        try {
            return Container::getInstance()->make(BillingManager::class)->accountWebhooks($found)->mapper;
        } catch (UnsupportedDriver) {
            return null;
        }
    }
}
