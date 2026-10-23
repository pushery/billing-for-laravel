<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * A second stored method the package charges once when the default could not pay a cycle, before dunning begins:
 * a card and a SEPA mandate, or two cards.
 *
 * Only a driver whose cycles the package bills itself can keep this promise, so only the Mollie driver binds it. It
 * is a contract of its own rather than part of PaymentMethods, so a PaymentMethods of your own does not have to
 * implement it: resolve it where the container has it, `app()->bound(KeepsAFallbackMethod::class)`.
 *
 * The method id is client-supplied, as in PaymentMethods, so an implementation must verify the method belongs to the
 * billable before acting.
 */
interface KeepsAFallbackMethod
{
    /** The billable's fallback method, or null when they keep none. */
    public function fallback(Model $billable): ?PaymentMethod;

    /**
     * Make one of the billable's stored methods the fallback, in place of any other. The default cannot be its own
     * fallback and is refused, because the fallback is charged at the moment the default has just failed.
     */
    public function setFallback(Model $billable, string $methodId): void;

    /** Keep no fallback: a cycle the default cannot pay goes to dunning, as it did before. */
    public function clearFallback(Model $billable): void;
}
