<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\MandateNeedsRedirect;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\PaymentMethod;
use RuntimeException;

/**
 * Stored payment methods under Mollie, where a method IS a local mandate row.
 *
 * Stripe answers these questions by asking Stripe, which enforces ownership on the package's behalf.
 * Mollie's mandates are established by a first payment and mirrored locally, so every question here is
 * answered from `payment_mandates` — and ownership becomes this class's job.
 *
 * **That is why each mutating call re-reads the row scoped to the billable rather than by id alone.** The
 * method id travels from a browser: it is not a secret, and an implementation that takes it on trust lets
 * any signed-in customer point their billing at, or delete, somebody else's mandate. The contract states
 * the precondition; this is where it is kept.
 *
 * Two removal rules exist because removal is the operation that can quietly break a paying customer:
 *
 * - Removing the DEFAULT promotes another usable mandate. Otherwise the account keeps methods but has no
 *   default, the engine finds nothing to charge, and a subscriber with a perfectly good second card is
 *   dunned for having removed their first.
 * - Removing the LAST one is refused while a subscription is still being charged. It would not end the
 *   subscription — it would just make the next cycle fail — so the screen is told to say so instead.
 */
final readonly class MolliePaymentMethods implements PaymentMethods
{
    /**
     * Mollie has no hosted page for adding a payment method, and that is not a gap to fill later.
     *
     * Its equivalent is the first-payment redirect, which needs an amount and a return URL this call has no
     * way to invent. Null is what makes a screen fall through to the in-app flow rather than render a link
     * to nowhere.
     */
    public function addMethodUrl(Model $billable): ?string
    {
        unset($billable);

        return null;
    }

    /**
     * The driver-shaped payload for capturing a method with Mollie's own front end.
     *
     * It refuses rather than returning an empty intent when the billable has no Mollie customer yet: a
     * first payment cannot be started for nobody, and an intent with no customer would fail at the
     * provider — one round trip later, with a message about Mollie rather than about this install.
     */
    public function setupIntent(Model $billable): ClientIntent
    {
        $customer = $this->customerReferenceOf($billable);

        if ($customer === null) {
            throw MandateNeedsRedirect::forDriver('Mollie');
        }

        return new ClientIntent(
            driver: 'mollie',
            payload: ['customerReference' => $customer, 'sequenceType' => 'first'],
            // A Mollie mandate is by definition reusable — it exists to be charged off-session, and the
            // methods it can exist for are exactly the recurring-capable set the capabilities derive.
            offSessionCapable: true,
        );
    }

    /** @return list<PaymentMethod> */
    public function all(Model $billable): array
    {
        $methods = [];

        foreach ($this->chargeable($billable)->orderByDesc('is_default')->orderBy('id')->cursor() as $mandate) {
            $methods[] = new PaymentMethod(
                id: (string) $mandate->mandate_reference,
                type: (string) ($mandate->method ?? 'mandate'),
                isDefault: (bool) $mandate->is_default,
            );
        }

        return $methods;
    }

    public function default(Model $billable): ?PaymentMethod
    {
        $default = $this->chargeable($billable)->where('is_default', true)->first();

        if (! $default instanceof PaymentMandate) {
            return null;
        }

        return new PaymentMethod(
            id: (string) $default->mandate_reference,
            type: (string) ($default->method ?? 'mandate'),
            isDefault: true,
        );
    }

    public function setDefault(Model $billable, string $methodId): void
    {
        $this->ownedBy($billable, $methodId)->makeDefault();
    }

    public function remove(Model $billable, string $methodId): void
    {
        $mandate = $this->ownedBy($billable, $methodId);

        DB::transaction(function () use ($billable, $mandate): void {
            $remaining = $this->chargeable($billable)
                ->whereKeyNot($mandate->getKey())
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get();

            if ($remaining->isEmpty() && $this->isBeingCharged($billable)) {
                throw new RuntimeException(
                    'This is the only payment method on the account and a subscription is still being '.
                    'charged against it. Removing it would not end the subscription — the next cycle would '.
                    'simply fail — so cancel the subscription first, or add another method.'
                );
            }

            $wasDefault = (bool) $mandate->is_default;
            $mandate->delete();

            $successor = $remaining->first();

            if ($wasDefault && $successor instanceof PaymentMandate) {
                $successor->makeDefault();
            }
        });
    }

    /**
     * The billable's mandate with this reference, or an exception.
     *
     * Scoped to the owner in the QUERY rather than fetched and then compared, so there is no window in
     * which the wrong row is in hand — and no chance of a comparison that reads the right field of the
     * wrong record.
     */
    private function ownedBy(Model $billable, string $methodId): PaymentMandate
    {
        $mandate = $this->chargeable($billable)->where('mandate_reference', $methodId)->first();

        if (! $mandate instanceof PaymentMandate) {
            // Deliberately the same answer for "does not exist" and "belongs to somebody else". Telling
            // them apart would confirm to a caller that a reference they guessed is real.
            throw new InvalidArgumentException(
                "No usable payment method {$methodId} belongs to this account."
            );
        }

        return $mandate;
    }

    /** Whether anything is still being charged, which is what makes the last method load-bearing. */
    private function isBeingCharged(Model $billable): bool
    {
        return Subscription::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('provider', 'mollie')
            ->whereIn('status', [
                SubscriptionState::Active->value,
                SubscriptionState::Grace->value,
                SubscriptionState::PastDue->value,
            ])
            ->exists();
    }

    /**
     * The billable's mandates that can actually be charged.
     *
     * A revoked mandate is not a payment method the customer has. Offering it back would let them set as
     * default something the bank already withdrew, and the failure surfaces a cycle later as a dunning
     * notice rather than as the refusal it is.
     *
     * @return Builder<PaymentMandate>
     */
    private function chargeable(Model $billable): Builder
    {
        return PaymentMandate::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('provider', 'mollie')
            ->where('status', PaymentMandate::CHARGEABLE);
    }

    /** The Mollie customer this billable is known by, read from any mandate already stored for them. */
    private function customerReferenceOf(Model $billable): ?string
    {
        $reference = $this->chargeable($billable)->value('customer_reference');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }
}
