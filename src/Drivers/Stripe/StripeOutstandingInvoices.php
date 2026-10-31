<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\PaysOutstandingInvoices;
use Pushery\Billing\Enums\OutstandingPaymentOutcome;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\OutstandingPayment;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Invoice;
use Stripe\StripeClient;

/**
 * Pays the open invoice of a Stripe subscription at the owner's request.
 *
 * The invoice is the subscription's latest one, which is what keeps it past due, and Stripe charges the method the
 * subscription names, else the customer's default. That is the card a recovery has just collected, since such a card
 * becomes the default of every subscription that names one of its own. The owner is present when they ask, so the
 * payment is made on-session, and a card that wants 3-D Secure asks for it instead of being declined. Stripe's
 * `invoice.paid` and `customer.subscription.updated` then sync the local row back, as after every other action.
 */
final readonly class StripeOutstandingInvoices implements PaysOutstandingInvoices
{
    /** The host of Stripe's hosted invoice page, where a cardholder confirms the payment of an invoice. */
    private const array INVOICE_HOSTS = ['invoice.stripe.com'];

    /** Stripe's code for an invoice whose payment the cardholder has to confirm. */
    private const string REQUIRES_ACTION = 'invoice_payment_intent_requires_action';

    public function __construct(
        private StripeClient $stripe,
        private CanTransactMoney $eligibility,
    ) {}

    public function payOutstanding(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): OutstandingPayment
    {
        // Paying moves money, so the gate is asked as a swap asks it, even for a caller that skipped the screen's check.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $invoice = $this->openInvoiceOf($billable, $merchant, $type);

        if (! $invoice instanceof Invoice) {
            return new OutstandingPayment(OutstandingPaymentOutcome::NothingOwed);
        }

        try {
            $paid = $this->stripe->invoices->pay($invoice->id, ['off_session' => false]);
        } catch (RateLimitException $rateLimited) {
            // A 429 is a subclass of InvalidRequestException in the SDK, and says nothing about the payment.
            throw $rateLimited;
        } catch (CardException|InvalidRequestException $refusal) {
            return $this->refused($invoice, $refusal);
        }

        return new OutstandingPayment(
            $paid->status === 'paid' ? OutstandingPaymentOutcome::Paid : OutstandingPaymentOutcome::Pending,
            $invoice->id,
        );
    }

    /**
     * What a refused payment comes to: a confirmation the cardholder has to give, an invoice that was paid in the
     * meantime, or a decline.
     */
    private function refused(Invoice $invoice, CardException|InvalidRequestException $refusal): OutstandingPayment
    {
        if ($refusal->getStripeCode() === self::REQUIRES_ACTION) {
            return new OutstandingPayment(
                OutstandingPaymentOutcome::NeedsConfirmation,
                $invoice->id,
                confirmationUrl: SafeExternalUrl::onHosts($invoice->hosted_invoice_url ?? null, self::INVOICE_HOSTS),
            );
        }

        if ($refusal instanceof InvalidRequestException) {
            // A request that paid the invoice a moment earlier, a second click or Stripe's own retry, has Stripe refuse
            // this one. The invoice as it stands now answers that, and any other refusal travels.
            if ($this->stripe->invoices->retrieve($invoice->id)->status === 'paid') {
                return new OutstandingPayment(OutstandingPaymentOutcome::Paid, $invoice->id);
            }

            throw $refusal;
        }

        return new OutstandingPayment(
            OutstandingPaymentOutcome::Declined,
            $invoice->id,
            failureCode: $refusal->getDeclineCode() ?? $refusal->getStripeCode(),
            failureMessage: $refusal->getMessage(),
        );
    }

    /**
     * The latest invoice of the owner's Stripe subscription while it is open, or null.
     *
     * A subscription paid from a balance has no Stripe invoice to pay, and neither has one Stripe no longer knows.
     */
    private function openInvoiceOf(Model $billable, ?MerchantScope $merchant, ?string $type): ?Invoice
    {
        $subscription = Subscription::model()::query()
            ->forOwner($billable)
            ->forMerchant($merchant)
            ->ofType($type)
            ->latest('id')
            ->first();

        $reference = $subscription instanceof Subscription && $subscription->provider === 'stripe' ? $subscription->provider_id : null;

        if ($reference === null || $reference === '') {
            return null;
        }

        try {
            $invoice = $this->stripe->subscriptions->retrieve($reference, ['expand' => ['latest_invoice']])->latest_invoice;
        } catch (RateLimitException $rateLimited) {
            throw $rateLimited;
        } catch (InvalidRequestException $refusal) {
            if ($refusal->getHttpStatus() === 404) {
                return null;
            }

            throw $refusal;
        }

        return $invoice instanceof Invoice && $invoice->status === 'open' && $invoice->amount_remaining > 0 ? $invoice : null;
    }
}
