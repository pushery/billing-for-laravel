<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MarketplaceWebhookEventMapper;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\BillingWebhookEvent;
use Pushery\Billing\Models\WebhookEffectRun;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

/**
 * Re-drives stored webhook deliveries through the effect bus.
 *
 * A provider retries a failed webhook for a while and then gives up — Stripe stops after ~3 days. If an
 * effect was still failing when that window closed (a bug, an outage, a migration that had not run yet),
 * the work is simply never done again and no one is coming back to do it: an add-on stays uncredited, a
 * plan stays out of sync. That is the gap this closes. The receiver stores every verified delivery WITH
 * its raw payload, so the package can re-drive it itself, on its own schedule, long after the provider
 * has stopped caring.
 *
 * Replay is SAFE to run twice because it goes through the same ledger the live path does: an effect run
 * already marked handled refuses the claim and is skipped, so a customer is not credited or mailed twice.
 * Only failed (and never-completed) runs actually re-run. Signature verification is not repeated — these
 * payloads were verified when they arrived; nothing unverified is ever stored.
 */
final class ReplayWebhooksCommand extends Command
{
    protected $signature = 'billing:webhooks:replay
        {--event=* : Replay these provider event ids (repeatable)}
        {--failed : Replay every delivery that has a failed effect run}
        {--since= : Only deliveries received after this date (e.g. "-7 days")}
        {--limit=100 : Stop after this many deliveries}
        {--dry-run : List what would be replayed, change nothing}';

    protected $description = 'Re-drive stored webhook deliveries whose effects failed';

    public function handle(WebhookEventMapper $platform, WebhookEffectRegistry $registry): int
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $this->option('event'), is_string(...)));
        $failedOnly = (bool) $this->option('failed');

        if ($ids === [] && ! $failedOnly) {
            $this->error('Refusing to replay everything: pass --event=<id> or --failed.');

            return self::INVALID;
        }

        $deliveries = $this->deliveries($ids, $failedOnly);

        if ($deliveries === []) {
            $this->info('Nothing to replay.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $queued = 0;
        $silent = 0;

        foreach ($deliveries as $delivery) {
            $events = $this->mapperFor($delivery, $platform)->map($this->rebuild($delivery));
            $effects = 0;

            foreach ($events as $event) {
                $effects += count($registry->for($event));

                if (! $dryRun) {
                    $registry->dispatch($event, $delivery);
                }
            }

            $queued += $effects;

            $this->line(sprintf(
                '%s %s (%s) → %d effect(s)',
                $dryRun ? 'would replay' : 'replayed',
                $delivery->event_id,
                $delivery->type,
                $effects,
            ));

            // A delivery that produced nothing is REPORTED, because "replayed … → 0 effect(s)" reads as
            // success and is exactly how a merchant delivery used to disappear from a bulk replay. Not a
            // non-zero exit, though: one unhandled event type in two hundred deliveries would turn the whole
            // run red, and an operator who learns to ignore a red replay has lost the signal again.
            if ($effects === 0) {
                $silent++;

                $this->components->warn(sprintf(
                    '%s (%s) from %s mapped to nothing — no effect was queued for it.',
                    $delivery->event_id,
                    $delivery->type,
                    $delivery->account_reference === '' ? 'the platform account' : $delivery->account_reference,
                ));
            }
        }

        $this->info(sprintf(
            '%s %d effect(s) across %d deliver%s, %d mapped to nothing.',
            $dryRun ? 'Would queue' : 'Queued',
            $queued,
            count($deliveries),
            count($deliveries) === 1 ? 'y' : 'ies',
            $silent,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $ids
     * @return list<BillingWebhookEvent>
     */
    private function deliveries(array $ids, bool $failedOnly): array
    {
        $query = BillingWebhookEvent::query();

        if ($ids !== []) {
            $query->whereIn('event_id', $ids);
        }

        if ($failedOnly) {
            // A delivery is worth replaying when an EFFECT of it failed, or when the delivery itself never
            // finished (the request died mid-dispatch, so some effects may never have been queued at all).
            $query->where(fn (Builder $delivery): Builder => $delivery
                ->whereIn('status', [WebhookEventState::Failed, WebhookEventState::Pending])
                ->orWhereIn('id', WebhookEffectRun::query()
                    ->select('delivery_id')
                    ->where('status', WebhookEventState::Failed)
                    ->whereNotNull('delivery_id')));
        }

        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            $query->where('created_at', '>=', Carbon::parse($since));
        }

        $limit = (int) $this->option('limit');

        return array_values($query->orderBy('id')->limit(max($limit, 1))->get()->all());
    }

    /**
     * The mapper the delivery arrived through, chosen by where it came from.
     *
     * A merchant delivery and a platform delivery are read by different mappers on the live path, and the
     * ledger records which endpoint took each one. Replaying everything through the platform mapper is not a
     * near-miss: it has no `account.*` arm at all, so a merchant delivery fell to `default => []` and was
     * reported as replayed with zero effects — a capability update that never landed, on an account that
     * still cannot receive money.
     *
     * The second half is the expensive one. A connected-account subscription mapped without a merchant scope
     * resolves against the PLATFORM catalog: not inaction, but state written for the wrong seller.
     *
     * Resolved per delivery rather than injected, because the marketplace mapper is bound only where a
     * marketplace is set up. An installation with no merchant endpoint has no merchant deliveries either, so
     * the branch is never taken there — and if it somehow is, falling back with a warning beats fatally
     * failing a recovery run.
     */
    private function mapperFor(BillingWebhookEvent $delivery, WebhookEventMapper $platform): WebhookEventMapper
    {
        if ($delivery->account_reference === '') {
            return $platform;
        }

        if (! $this->laravel->bound(MarketplaceWebhookEventMapper::class)) {
            $this->components->warn(sprintf(
                'No merchant mapper is bound, so %s from %s is read by the platform mapper, which does not '
                .'know merchant events.',
                $delivery->event_id,
                $delivery->account_reference,
            ));

            return $platform;
        }

        return $this->laravel->make(MarketplaceWebhookEventMapper::class);
    }

    /**
     * Rebuild the request the provider sent from the payload we stored, so the SAME mapper that ran live
     * maps it — no second, replay-only mapping path that could drift out of step with the real one.
     */
    private function rebuild(BillingWebhookEvent $delivery): Request
    {
        return Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($delivery->payload ?? [], JSON_THROW_ON_ERROR),
        );
    }
}
