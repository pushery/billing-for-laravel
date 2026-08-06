<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Marketplace\SmallBusinessFlipSweep;

/**
 * Flip the creators who have outgrown their size relief.
 *
 * ## This command DECIDES something, which its siblings do not
 *
 * `billing:tax-holds:announce` tells merchants about a hold that already began. This one writes a new tax
 * standing: a creator whose turnover here has broken a small-business limit stops being exempt, from the
 * moment the breaking sale happened. That is a change to how their documents are taxed, so read the two
 * failure directions before scheduling it.
 *
 * Not running it is the loud failure by comparison — a creator who has outgrown the relief keeps issuing
 * tax-free documents, which is knowingly wrong the moment the limit is broken and stays wrong for every
 * document afterwards. Running it wrongly writes a standing nobody declared.
 *
 * ## What it never does
 *
 * It never flips a creator back. The count it works from sees only what was sold through THIS platform, so
 * it is a lower bound: over the limit here means over the limit for certain, while under the limit here
 * proves nothing at all. Returning somebody to a relief is a self-declaration with its own effective date.
 *
 * ## The founding year, and why this command can end successfully while telling you something is missing
 *
 * A business in its founding year is measured against a much lower limit. That year is recorded with the
 * declaration and is nullable, so some creators have none — and for those, the run applies the ordinary
 * limit, which is the higher one. It does not substitute a year: inventing when somebody's business started
 * would be a fact nobody stated, and the threshold reads an early year and a late one as different regimes
 * rather than as a blurred number.
 *
 * So they are named in the output. The run succeeds because the reconciliation it could do is correct and
 * worth keeping; what it could not test is on the screen instead of behind a silent zero.
 */
final class ReconcileTaxStatusCommand extends Command
{
    protected $signature = 'billing:tax-status:reconcile';

    protected $description = 'Flip creators whose turnover here has broken a small-business limit';

    public function handle(SmallBusinessFlipSweep $sweep): int
    {
        $report = $sweep->reconcile(CarbonImmutable::now());

        $this->components->info($report->examined === 0
            ? 'No creator currently rests on a size relief.'
            : "Reconciled {$report->examined} creator(s) against their turnover here.");

        if (! $report->complete()) {
            $missing = count($report->withoutFoundingYear);

            // Warned rather than failed. A non-zero exit would make a scheduler treat this as a broken job
            // and, in most setups, alert on it nightly forever — which is how a real warning gets muted.
            $this->components->warn(
                "{$missing} creator(s) have no founding year recorded, so the ordinary limit was applied to "
                .'them rather than the lower founding-year one. A creator in their first year may therefore '
                .'be over a limit this run did not test them against. Collect the year with their next '
                .'declaration: '.implode(', ', $report->withoutFoundingYear)
            );
        }

        // Reported after the missing-year warning and separately from it, because they are different
        // kinds of news: one is a gap in what we know, this is a fact about somebody's business. It is
        // not a defect and not an action for the operator — it is a heads-up to pass on, because a
        // creator who becomes standard rated has to register, and registration takes weeks. The first
        // day they owe tax is otherwise the first day they cannot charge it correctly.
        foreach ($report->approaching as $creator => $level) {
            $this->components->info(sprintf(
                '%s has reached %d%% of their small-business limit and is still relieved by size.',
                $creator,
                (int) round($level * 100),
            ));
        }

        return self::SUCCESS;
    }
}
