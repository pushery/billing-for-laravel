<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Http\Request;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\AccountPaymentStatus;
use Pushery\Billing\Events\AccountPaymentChanged;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * Reports the signed events about the payments of one Stripe payment account as AccountPaymentChanged.
 *
 * Stripe signs each event, so its type says what happened. An event about a payment intent carries the intent. An
 * event about a refund, a failed refund or a dispute only names it, so the intent is read back with the account's
 * client, together with its latest charge, and the amounts and the metadata are the payment's own.
 */
final readonly class StripeAccountWebhookEventMapper implements WebhookEventMapper
{
    /** The events about a payment intent, and where each leaves the payment. */
    private const array INTENT_EVENTS = [
        'payment_intent.processing' => AccountPaymentStatus::Pending,
        'payment_intent.amount_capturable_updated' => AccountPaymentStatus::Authorized,
        'payment_intent.succeeded' => AccountPaymentStatus::Paid,
        'payment_intent.payment_failed' => AccountPaymentStatus::Failed,
        'payment_intent.canceled' => AccountPaymentStatus::Canceled,
    ];

    /** The events about a refund, which report one only where it failed. */
    private const array REFUND_EVENTS = ['refund.failed', 'refund.updated', 'charge.refund.updated'];

    public function __construct(
        private StripeClient $stripe,
        private string $account,
    ) {}

    /** @return iterable<AccountPaymentChanged> */
    public function map(Request $request): iterable
    {
        $payload = json_decode($request->getContent(), true);
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_string($type) || ! is_array($object)) {
            return [];
        }

        $id = $object['id'] ?? null;

        $change = match (true) {
            array_key_exists($type, self::INTENT_EVENTS) => $this->ofIntent($object, self::INTENT_EVENTS[$type]),
            $type === 'charge.refunded' => $this->ofNamedIntent($object['payment_intent'] ?? null),
            $type === 'charge.dispute.created' => $this->ofNamedIntent($object['payment_intent'] ?? null, AccountPaymentStatus::ChargedBack, $this->moneyOf($object)),
            in_array($type, self::REFUND_EVENTS, true) && ($object['status'] ?? null) === 'failed' => $this->ofNamedIntent(
                $object['payment_intent'] ?? null,
                AccountPaymentStatus::RefundFailed,
                refund: is_string($id) ? $id : null,
            ),
            default => null,
        };

        return $change instanceof AccountPaymentChanged ? [$change] : [];
    }

    /**
     * What an event about the intent itself reports, from the intent it carries. Nothing has gone back at that point,
     * so the refunded and the charged-back amount are zero.
     *
     * @param  array<array-key, mixed>  $intent
     */
    private function ofIntent(array $intent, AccountPaymentStatus $status): ?AccountPaymentChanged
    {
        $amount = $this->moneyOf($intent);
        $id = $intent['id'] ?? null;

        if (! $amount instanceof Money || ! is_string($id)) {
            return null;
        }

        return $this->change($id, $status, $amount, Money::zero($amount->currency), Money::zero($amount->currency), $intent['metadata'] ?? null);
    }

    /**
     * What an event about a refund or a dispute reports, from the intent it names, read back with its latest charge.
     * Without a status of its own, the refunded amount decides between a partial and a full refund.
     */
    private function ofNamedIntent(mixed $intentId, ?AccountPaymentStatus $status = null, ?Money $chargedBack = null, ?string $refund = null): ?AccountPaymentChanged
    {
        if (! is_string($intentId) || $intentId === '') {
            return null;
        }

        $intent = $this->stripe->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']])->toArray();
        $amount = $this->moneyOf($intent);

        if (! $amount instanceof Money) {
            return null;
        }

        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $refunded = is_int($charge['amount_refunded'] ?? null)
            ? StripeAmount::toMoney($charge['amount_refunded'], $amount->currency)
            : Money::zero($amount->currency);

        return $this->change(
            $intentId,
            $status ?? ($refunded->lessThan($amount) ? AccountPaymentStatus::PartiallyRefunded : AccountPaymentStatus::Refunded),
            $amount,
            $refunded,
            $chargedBack ?? Money::zero($amount->currency),
            $intent['metadata'] ?? null,
            $refund,
        );
    }

    private function change(string $intentId, AccountPaymentStatus $status, Money $amount, Money $refunded, Money $chargedBack, mixed $metadata, ?string $refund = null): AccountPaymentChanged
    {
        $values = is_array($metadata) ? $metadata : [];
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== ChargeOptions::RESERVED_METADATA_KEY && (is_string($value) || is_int($value))) {
                $kept[$key] = $value;
            }
        }

        $reference = $values[ChargeOptions::RESERVED_METADATA_KEY] ?? null;

        return new AccountPaymentChanged(
            'stripe',
            $this->account,
            $intentId,
            $status,
            $amount,
            $refunded,
            $chargedBack,
            is_string($reference) && $reference !== '' ? $reference : null,
            $kept,
            $refund,
        );
    }

    /**
     * The amount of a Stripe object that states one, or null where it states none.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function moneyOf(array $object): ?Money
    {
        $amount = $object['amount'] ?? null;
        $currency = $object['currency'] ?? null;

        return is_int($amount) && is_string($currency) && $currency !== '' ? StripeAmount::toMoney($amount, $currency) : null;
    }
}
