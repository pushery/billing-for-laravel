<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\AccountPaymentStatus;
use Pushery\Billing\Enums\ChargebackOutcome;
use Pushery\Billing\Events\AccountPaymentChanged;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentChargeback;
use Pushery\Billing\Webhooks\AccountWebhooks;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Reports the signed events about the payments of one Stripe payment account as AccountPaymentChanged.
 *
 * Stripe signs each event, so its type says what happened. An event about a payment intent carries the intent. An
 * event about a refund, a failed refund or a dispute only names it, so the intent is read back with the account's
 * client, together with its latest charge, and the amounts and the metadata are the payment's own. Its disputes are
 * read with it, so the change lists each chargeback with its time, reason and outcome, and what the bank holds
 * follows from them.
 *
 * A Checkout Session the customer never paid ends without a payment intent, because Stripe creates one only when
 * the customer pays. Its expiry is reported under the session's id, the reference its charge's result named.
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

    /**
     * The events about a dispute, each of which carries the dispute. An inquiry that becomes a chargeback stays the
     * same dispute and announces itself with `charge.dispute.funds_withdrawn`, and a won one with
     * `charge.dispute.funds_reinstated`, a late win included.
     */
    private const array DISPUTE_EVENTS = ['charge.dispute.created', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated', 'charge.dispute.closed'];

    /** The events about a refund, which report one only where it failed. */
    private const array REFUND_EVENTS = ['refund.failed', 'refund.updated', 'charge.refund.updated'];

    /** The event about a Checkout Session that ran out before the customer paid. */
    private const string SESSION_EXPIRED = 'checkout.session.expired';

    public function __construct(
        private StripeClient $stripe,
        private string $account,
    ) {}

    /**
     * Every event type this mapper reads, for the webhook endpoint of an account to subscribe to.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return [...array_keys(self::INTENT_EVENTS), 'charge.refunded', ...self::DISPUTE_EVENTS, ...self::REFUND_EVENTS, self::SESSION_EXPIRED];
    }

    /**
     * What a signed event reports, or, for a request that names a payment by its reference instead of carrying an
     * event, where that payment stands now, read back with the account's key: the `cs_` reference a charge on
     * Stripe's checkout page returned, or a payment intent. The webhook endpoint verifies the signature first, so a
     * reference reaches this only from the application's own code, through
     * {@see AccountWebhooks::read()}.
     *
     * @return iterable<AccountPaymentChanged>
     */
    public function map(Request $request): iterable
    {
        $payload = json_decode($request->getContent(), true);
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_string($type) || ! is_array($object)) {
            return $this->ofReference($request->input('id'));
        }

        $id = $object['id'] ?? null;

        $change = match (true) {
            array_key_exists($type, self::INTENT_EVENTS) => $this->ofIntent($object, self::INTENT_EVENTS[$type]),
            $type === 'charge.refunded' => $this->ofNamedIntent($object['payment_intent'] ?? null),
            in_array($type, self::DISPUTE_EVENTS, true) => $this->ofNamedIntent($object['payment_intent'] ?? null, dispute: $object),
            in_array($type, self::REFUND_EVENTS, true) && ($object['status'] ?? null) === 'failed' => $this->ofNamedIntent(
                $object['payment_intent'] ?? null,
                AccountPaymentStatus::RefundFailed,
                refund: is_string($id) ? $id : null,
            ),
            $type === self::SESSION_EXPIRED => $this->ofExpiredSession($object),
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

        return $this->change($id, $status, $amount, Money::zero($amount->currency), Money::zero($amount->currency), $intent['metadata'] ?? null, testmode: ($intent['livemode'] ?? null) === false);
    }

    /**
     * Where the payment a reference names stands, as its webhook would report it, or nothing for a reference that is
     * neither a session nor an intent, or one Stripe does not know. An error that says nothing about the payment
     * travels.
     *
     * @return list<AccountPaymentChanged>
     */
    private function ofReference(mixed $reference): array
    {
        if (! is_string($reference)) {
            return [];
        }

        try {
            $change = match (true) {
                str_starts_with($reference, 'cs_') => $this->ofSessionReference($reference),
                str_starts_with($reference, 'pi_') => $this->ofIntentReference($reference),
                default => null,
            };
        } catch (RateLimitException $rateLimited) {
            // A 429 is a subclass of InvalidRequestException in the SDK, and says nothing about the payment.
            throw $rateLimited;
        } catch (InvalidRequestException $refusal) {
            if ($refusal->getHttpStatus() !== 404) {
                throw $refusal;
            }

            $change = null;
        }

        return $change instanceof AccountPaymentChanged ? [$change] : [];
    }

    /**
     * A Checkout Session read back: an expired one as `checkout.session.expired` reports it, a completed one through
     * its payment intent, as the intent's events report it, and an open one as nothing. The customer may still pay an
     * open session, with another method after a declined one, so it has no state to report yet.
     */
    private function ofSessionReference(string $sessionId): ?AccountPaymentChanged
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId)->toArray();
        $intent = $session['payment_intent'] ?? null;

        return match ($session['status'] ?? null) {
            'expired' => $this->ofExpiredSession($session),
            'complete' => is_string($intent) && $intent !== '' ? $this->ofIntentReference($intent) : null,
            default => null,
        };
    }

    /**
     * A payment intent read back, in the status its own state names, as the intent's events report it. One that still
     * waits for a method, a confirmation or an action reports nothing; one whose last attempt was declined reports
     * `Failed`, as `payment_intent.payment_failed` does.
     */
    private function ofIntentReference(string $intentId): ?AccountPaymentChanged
    {
        $intent = $this->stripe->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']])->toArray();

        return match ($intent['status'] ?? null) {
            'succeeded' => $this->standingOf($intentId, $intent),
            'processing' => $this->ofIntent($intent, AccountPaymentStatus::Pending),
            'requires_capture' => $this->ofIntent($intent, AccountPaymentStatus::Authorized),
            'canceled' => $this->ofIntent($intent, AccountPaymentStatus::Canceled),
            'requires_payment_method' => is_array($intent['last_payment_error'] ?? null) ? $this->ofIntent($intent, AccountPaymentStatus::Failed) : null,
            default => null,
        };
    }

    /**
     * What an expired Checkout Session reports: its amount, nothing gone back, and the metadata its charge set. A
     * session in another mode than `payment` takes no payment and reports nothing.
     *
     * @param  array<array-key, mixed>  $session
     */
    private function ofExpiredSession(array $session): ?AccountPaymentChanged
    {
        $id = $session['id'] ?? null;
        $total = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (! is_string($id) || ($session['mode'] ?? null) !== 'payment' || ! is_int($total) || ! is_string($currency) || $currency === '') {
            return null;
        }

        $amount = StripeAmount::toMoney($total, $currency);

        return $this->change($id, AccountPaymentStatus::Expired, $amount, Money::zero($amount->currency), Money::zero($amount->currency), $session['metadata'] ?? null, testmode: ($session['livemode'] ?? null) === false);
    }

    /**
     * What an event about a refund or a dispute reports, from the intent it names, read back with its latest charge and
     * its chargebacks.
     *
     * A chargeback the bank holds, open or lost, outranks the rest, because the bank holds the money whatever happened
     * to the refunds. Without one, a failed refund says so, and otherwise the refunded amount decides between a payment
     * that stands paid, a partial and a full refund.
     *
     * @param  array<array-key, mixed>|null  $dispute  the dispute the event carries, as it stands now
     */
    private function ofNamedIntent(mixed $intentId, ?AccountPaymentStatus $status = null, ?string $refund = null, ?array $dispute = null): ?AccountPaymentChanged
    {
        if (! is_string($intentId) || $intentId === '') {
            return null;
        }

        return $this->standingOf($intentId, $this->stripe->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']])->toArray(), $status, $refund, $dispute);
    }

    /**
     * Where a paid intent stands, from the intent read back with its latest charge.
     *
     * @param  array<array-key, mixed>  $intent
     * @param  array<array-key, mixed>|null  $dispute
     */
    private function standingOf(string $intentId, array $intent, ?AccountPaymentStatus $status = null, ?string $refund = null, ?array $dispute = null): ?AccountPaymentChanged
    {
        $amount = $this->moneyOf($intent);

        if (! $amount instanceof Money) {
            return null;
        }

        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $refunded = is_int($charge['amount_refunded'] ?? null)
            ? StripeAmount::toMoney($charge['amount_refunded'], $amount->currency)
            : Money::zero($amount->currency);
        $chargebacks = $this->chargebacksOf($intentId, $dispute);
        $held = Money::zero($amount->currency);

        foreach ($chargebacks as $chargeback) {
            if ($chargeback->outcome !== ChargebackOutcome::Reversed && $chargeback->amount->currency === $amount->currency) {
                $held = $held->plus($chargeback->amount);
            }
        }

        return $this->change(
            $intentId,
            match (true) {
                $held->isPositive() => AccountPaymentStatus::ChargedBack,
                $status instanceof AccountPaymentStatus => $status,
                ! $refunded->isPositive() => AccountPaymentStatus::Paid,
                $refunded->lessThan($amount) => AccountPaymentStatus::PartiallyRefunded,
                default => AccountPaymentStatus::Refunded,
            },
            $amount,
            $refunded,
            $held,
            $intent['metadata'] ?? null,
            $refund,
            ($intent['livemode'] ?? null) === false,
            $chargebacks,
        );
    }

    /**
     * The chargebacks of the payment intent: those Stripe lists, with the dispute the event carries in place of its
     * listed copy, because the event is the newer of the two. Where the key may not read disputes, the event's own
     * dispute is the list.
     *
     * @param  array<array-key, mixed>|null  $dispute
     * @return list<PaymentChargeback>
     */
    private function chargebacksOf(string $intentId, ?array $dispute): array
    {
        $own = $dispute !== null ? $this->chargebackOf($dispute) : null;
        $ownId = $dispute !== null && is_string($dispute['id'] ?? null) ? $dispute['id'] : null;
        $chargebacks = [];
        $placed = false;

        foreach ($this->listedChargebacks($intentId) as $listed) {
            if ($ownId === null || $listed->reference !== $ownId) {
                $chargebacks[] = $listed;

                continue;
            }

            // The event's copy decides, and an inquiry it carries has no place in the list at all.
            if ($own instanceof PaymentChargeback) {
                $chargebacks[] = $own;
            }

            $placed = true;
        }

        if (! $placed && $own instanceof PaymentChargeback) {
            $chargebacks[] = $own;
        }

        return $chargebacks;
    }

    /**
     * Every chargeback Stripe lists for the payment intent, read on each event that reads the intent back. An event
     * about the intent itself is not one of them: it carries the intent, and a dispute needs a charge that succeeded
     * before it.
     *
     * @return list<PaymentChargeback>
     */
    private function listedChargebacks(string $intentId): array
    {
        try {
            $listed = $this->stripe->disputes->all(['payment_intent' => $intentId, 'limit' => 100]);
        } catch (PermissionException $exception) {
            // A restricted key the merchant gave no right to read disputes keeps failing this read, and a delivery
            // that failed on it would fail for good. The change goes out with the dispute its event carries at most,
            // and the warning names the account, so the right can be added (`disputes_read`, which missingRights()
            // asks for).
            Log::warning('billing: a Stripe account may not read disputes, so its payment change counts only the dispute its event carries', [
                'account' => $this->account,
                'payment' => $intentId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $chargebacks = [];

        foreach ($listed as $dispute) {
            $chargeback = $this->chargebackOf($dispute->toArray());

            if ($chargeback instanceof PaymentChargeback) {
                $chargebacks[] = $chargeback;
            }
        }

        return $chargebacks;
    }

    /**
     * One dispute as a chargeback, with its time, reason and outcome, or null where it is none.
     *
     * A dispute that was won gave the money back, and a lost one leaves it with the bank. An inquiry (`warning_*`) and
     * a prevented dispute never became a chargeback, and Stripe takes no money for either. Stripe names a reason by its
     * code alone, such as `fraudulent`.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function chargebackOf(array $values): ?PaymentChargeback
    {
        $amount = $this->moneyOf($values);
        $id = $values['id'] ?? null;
        $status = $values['status'] ?? null;

        if (! $amount instanceof Money || ! is_string($id) || $id === '' || ! is_string($status) || str_starts_with($status, 'warning_') || $status === 'prevented') {
            return null;
        }

        $created = $values['created'] ?? null;
        $reason = $values['reason'] ?? null;

        return new PaymentChargeback(
            $id,
            $amount,
            match ($status) {
                'won' => ChargebackOutcome::Reversed,
                'lost' => ChargebackOutcome::Lost,
                default => ChargebackOutcome::Open,
            },
            is_int($created) ? CarbonImmutable::createFromTimestampUTC($created) : null,
            is_string($reason) && $reason !== '' ? $reason : null,
        );
    }

    /**
     * The change, with the payment's own mode: Stripe marks every object of a test-mode request `livemode: false`.
     *
     * @param  list<PaymentChargeback>  $chargebacks
     */
    private function change(string $paymentReference, AccountPaymentStatus $status, Money $amount, Money $refunded, Money $chargedBack, mixed $metadata, ?string $refund = null, bool $testmode = false, array $chargebacks = []): AccountPaymentChanged
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
            $paymentReference,
            $status,
            $amount,
            $refunded,
            $chargedBack,
            is_string($reference) && $reference !== '' ? $reference : null,
            $kept,
            $refund,
            $testmode,
            $chargebacks,
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
