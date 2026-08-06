<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Marketplace\MarketAccessJournal;

/**
 * Writes down which markets were opened or closed, and by whom.
 *
 * Run when the market list changes. It is a separate act from editing the setting because the setting is a
 * file and the record is evidence: a return covering a period has to be able to show that the country it
 * declared was open at the time, and the file cannot say when it became so.
 *
 * Repeating it costs nothing — only actual changes are written, so running it on every deploy is a
 * reasonable habit rather than a way to fill the log with noise.
 */
final class RecordMarketAccessCommand extends Command
{
    protected $signature = 'billing:markets:record {--actor= : Who made the change}';

    protected $description = 'Record any market whose standing has changed since it was last recorded';

    public function handle(MarketAccessJournal $journal): int
    {
        $actor = $this->option('actor');
        $changes = $journal->sync(is_string($actor) && $actor !== '' ? $actor : null);

        if ($changes === []) {
            $this->components->info('No market has changed standing since the last record.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->components->twoColumnDetail(
                $change['country'],
                ($change['from'] instanceof MarketAccess ? $change['from']->value : 'unrecorded').' → '.$change['to']->value,
            );
        }

        $this->components->info(count($changes).' market change(s) recorded.');

        return self::SUCCESS;
    }
}
