<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The single canonical subscription state every account screen renders against. The
 * SubscriptionPresenter collapses a driver's overlapping predicates into exactly one of these,
 * so the whole dashboard agrees on what to show. Every state is column-derivable from the local
 * subscription-state model (no provider API call); provider-specific statuses are mapped onto
 * these by each driver.
 *
 * Provider-neutral by design: it does not know Stripe, Mollie or Adyen — each driver maps its own
 * status vocabulary onto these cases.
 */
enum SubscriptionState: string
{
    case None = 'none';                            // never subscribed, no customer record
    case Churned = 'churned';                      // had a customer, no live subscription row
    case Activating = 'activating';                // post-checkout/mutation, webhook not yet applied
    case GenericTrial = 'generic_trial';           // trial without a subscription
    case Trialing = 'trialing';                    // subscription in trial
    case Active = 'active';                         // paying, in good standing
    case PastDue = 'past_due';                      // payment failed (access blocked — hard dunning)
    case Incomplete = 'incomplete';                // first payment unconfirmed (SCA)
    case IncompleteExpired = 'incomplete_expired'; // abandoned first payment
    case Grace = 'grace';                           // canceled but paid through the period end
    case Paused = 'paused';                         // billing paused: no invoices are raised, no access
    case Ended = 'ended';                           // canceled and lapsed

    /**
     * The WireKit badge/callout intent, so status is conveyed by color AND text (never color
     * alone — accessibility). Maps to WireKit's intent vocabulary.
     */
    public function badgeIntent(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trialing, self::GenericTrial, self::Activating => 'info',
            // Paused is a warning, not neutral: the owner is neither being billed nor being served,
            // and only they can end that — it is a state to act on, not a resting one.
            self::Grace, self::Incomplete, self::Paused => 'warning',
            self::PastDue, self::IncompleteExpired => 'danger',
            self::None, self::Churned, self::Ended => 'neutral',
        };
    }

    /**
     * Whether this state blocks access under hard dunning — the DunningGuard keys on exactly these.
     * A synced past_due/incomplete subscription pulls the tier to zero, so the hot path must know
     * to block rather than silently grant the free allowance.
     *
     * Paused is deliberately NOT blocking. A pause is something the owner chose, not a debt: it must
     * not start the delinquency clock, walk them up the dunning ladder, or mail them about a failed
     * payment they never made.
     */
    public function isBlocking(): bool
    {
        return $this === self::PastDue || $this === self::Incomplete;
    }

    /** Whether the owner is on some form of trial (generic or subscription-backed). */
    public function isTrialing(): bool
    {
        return $this === self::GenericTrial || $this === self::Trialing;
    }

    /**
     * Whether this state currently grants entitlement access (paying, on grace, or trialing in good
     * standing). Callers still combine this with the resolved tier — a state never implies a tier.
     *
     * Paused grants nothing: no invoice is being raised, so no paid tier is being paid for.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::Grace, self::Trialing, self::GenericTrial => true,
            default => false,
        };
    }
}
