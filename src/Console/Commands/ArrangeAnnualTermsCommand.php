<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Consumer\AnnualTerms;
use Pushery\Billing\Models\Subscription;
use Throwable;

/**
 * Arranges the end of every consumer's running annual term once more, where nothing that goes on past the period
 * holds it.
 *
 * ## What it repairs
 *
 * A term is arranged when its subscription begins, by the effect of its first event. Where that arrangement never
 * finished, nothing arranges the term again: every later event finds a schedule on the subscription, or one recorded
 * on its row, and leaves it alone. Stripe refused the later phases of every schedule the package wrote from 0.53.0 to
 * 0.56.x, so each consumer's annual subscription it arranged then is held by a schedule of its year alone, and that
 * year would renew as a year.
 *
 * ## What it leaves alone
 *
 * The driver keeps an arrangement that goes on past the period, so a term arranged already stays as it is and a second
 * run changes nothing. A business's term, one that is ending, one the customer booked a further year after and one the
 * package's own engine bills are not asked about at all.
 *
 * `--dry-run` names the subscriptions a run would ask the driver about and asks nothing, since only the driver can tell
 * an arrangement in place from one to make again.
 */
final class ArrangeAnnualTermsCommand extends Command
{
    protected $signature = 'billing:annual-terms:arrange {--dry-run : Name the subscriptions a run would ask the driver about, and change nothing}';

    protected $description = 'Arrange the end of every consumer\'s running annual term once more, where nothing that goes on past it holds it.';

    public function handle(AnnualTerms $terms): int
    {
        $dryRun = $this->option('dry-run') === true;
        $asked = 0;
        $arranged = 0;
        $failed = 0;

        Subscription::model()::query()
            ->whereNull('ends_at')
            ->where('status', 'active')
            ->whereNotNull('provider_id')
            ->with('owner')
            ->chunkById(100, function (Collection $subscriptions) use ($terms, $dryRun, &$asked, &$arranged, &$failed): void {
                /** @var Collection<int, Subscription> $subscriptions */
                foreach ($subscriptions as $subscription) {
                    $owner = $subscription->getRelationValue('owner');

                    if (! $owner instanceof Model || ! $terms->canArrangeAgain($owner, $subscription)) {
                        continue;
                    }

                    $asked++;
                    $label = 'subscription '.$this->keyOf($subscription).' ('.$subscription->provider_id.')';

                    if ($dryRun) {
                        $this->line('  would ask about '.$label);

                        continue;
                    }

                    $before = $subscription->provider_schedule_id;

                    try {
                        $arrangement = $terms->arrangeAgain($owner, $subscription);
                    } catch (Throwable $refused) {
                        $failed++;
                        $this->components->error($label.': '.$refused->getMessage());

                        continue;
                    }

                    if ($arrangement !== $before) {
                        $arranged++;
                        $this->line('  arranged '.$label.': '.$arrangement.', recorded before: '.($before ?? 'none'));
                    }
                }
            });

        if ($dryRun) {
            $this->components->info("Would ask the driver about {$asked} annual term(s).");

            return self::SUCCESS;
        }

        $this->components->info("Arranged {$arranged} of {$asked} annual term(s) again.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function keyOf(Subscription $subscription): string
    {
        $key = $subscription->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
