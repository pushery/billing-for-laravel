<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Webhooks\HandleWebhookEffect;

/**
 * Names the job middleware of each webhook effect, the job that runs one effect of a delivery.
 *
 * An application that serves many payment accounts puts all their effects on one queue, and one account
 * with a burst of events puts its effects ahead of every other account's payments. The way to hold an
 * account to its share is the queue's own: a `RateLimited` middleware with a limiter keyed on the account,
 * which sends an effect over its share back to the queue while the effects of the other accounts run.
 * The job carries what such a limiter keys on, `provider` and `accountReference`, the account the
 * delivery came from, with `''` for the platform's own endpoint.
 *
 * It is asked on the worker, each time the job is about to run, so an answer can depend on the job. The
 * shipped default names none.
 */
interface WebhookEffectMiddleware
{
    /**
     * The middleware the job runs through, outermost first.
     *
     * @return list<object>
     */
    public function for(HandleWebhookEffect $job): array;
}
