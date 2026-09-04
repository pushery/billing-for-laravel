<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\OrderItemPreprocessor;
use Pushery\Billing\Exceptions\OrderItemPreprocessorFailed;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\OrderItemDraft;
use Throwable;

/**
 * The configured chain, run in the order it is written.
 *
 * Order is part of the configuration rather than an accident of resolution: a step that prices metered
 * usage and a step that applies a percentage coupon give different answers depending on which runs first,
 * and the only place that ordering can honestly live is the list the operator wrote.
 *
 * The default is an empty chain, so an install that configures nothing behaves exactly as it did before
 * this existed — the flat plan price, and nothing else.
 *
 * A step that throws stops the cycle. The alternative — catching it and continuing with the lines the
 * chain had reached — is the worst available outcome: the order would be claimed and charged against a
 * total that no configuration reproduces, and because the cycle is claimed the next tick would skip it.
 * A cycle that failed loudly is retried; a cycle that was half-priced is billed.
 */
final readonly class OrderItemPreprocessorChain
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    /**
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    public function handle(array $drafts, Subscription $subscription): array
    {
        foreach ($this->steps() as $step) {
            $drafts = $this->runStep($step, $drafts, $subscription);
        }

        return $drafts;
    }

    /**
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    private function runStep(OrderItemPreprocessor $step, array $drafts, Subscription $subscription): array
    {
        try {
            return array_values($step->handle($drafts, $subscription));
        } catch (Throwable $failure) {
            throw OrderItemPreprocessorFailed::in($step::class, $failure);
        }
    }

    /**
     * The configured steps, resolved through the container so a step may declare its own dependencies.
     *
     * A configured entry that is not a preprocessor is skipped rather than fataled on: the value comes
     * from a consumer's config file, and a typo there should cost that one step rather than every billing
     * run in the install. That includes a string naming no class at all — the check has to happen before
     * the container sees it, because the container's own answer to an unknown name is an exception.
     *
     * @return list<OrderItemPreprocessor>
     */
    private function steps(): array
    {
        $configured = $this->config->get('billing.order_item_preprocessors', []);

        if (! is_array($configured)) {
            return [];
        }

        $steps = [];

        foreach ($configured as $entry) {
            $step = $entry;

            if (is_string($entry)) {
                // Checked BEFORE resolving, because the container throws on a name that is not a class —
                // and it throws from inside the chain, where it would abort the billing run rather than be
                // skipped. A typo in a consumer's config file must cost that one step, nothing more.
                if (! class_exists($entry)) {
                    continue;
                }

                $step = $this->container->make($entry);
            }

            if ($step instanceof OrderItemPreprocessor) {
                $steps[] = $step;
            }
        }

        return $steps;
    }
}
