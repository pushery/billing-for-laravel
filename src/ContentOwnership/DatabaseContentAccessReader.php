<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ContentAccessReader;
use Pushery\Billing\Contracts\ContentCatalog;
use Pushery\Billing\Contracts\SubscriptionContentScope;
use Pushery\Billing\Contracts\SubscriptionStateReader;
use Pushery\Billing\Enums\AccessVia;
use Pushery\Billing\Enums\ContentAvailability;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\ValueObjects\AccessDecision;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\OwnedWork;
use Pushery\Billing\ValueObjects\SubscriptionGrant;
use Pushery\Billing\ValueObjects\VersionResolution;

/**
 * The default reader: persisted ownership from this package's own register, unioned with the live
 * subscription view, and never a byte of content.
 *
 * ## The union, and the order it is evaluated in
 *
 * Ownership is checked first. Not for speed — because when both hold, ownership is the answer that must be
 * reported: it outlives the subscription, and naming the subscription would tell somebody their access ends
 * with their plan when it does not. Only when no grant is granting does the subscription get asked.
 *
 * ## Why the subscription half touches the database at most once
 *
 * `SubscriptionStateReader::grantsFor()` answers the whole marketplace in one indexed query, and the
 * consumer's scope is asked in memory, once per (subscription, work) pair. So a library screen with fifty
 * works costs one grant query, one subscription query and one batched catalog call — not fifty of anything.
 * That is the acceptance criterion, and it is a property of asking for the whole set up front rather than of
 * anything clever.
 */
final readonly class DatabaseContentAccessReader implements ContentAccessReader
{
    public function __construct(
        private SubscriptionStateReader $subscriptions,
        private SubscriptionContentScope $scope,
        private ContentCatalog $catalog,
        private UpdatePolicyResolver $policies,
    ) {}

    public function accessFor(Model $principal, ContentReference $content, ?CarbonInterface $on = null): AccessDecision
    {
        $moment = Carbon::parse($on ?? Carbon::now());

        return $this->decide(
            $content,
            $this->grantRowsFor($principal, $content),
            $this->subscriptions->grantsFor($principal, $moment),
            $this->availabilityOf([$content], $moment)[$content->key()],
            $moment,
        );
    }

    public function grantsFor(Model $principal, ?CarbonInterface $on = null): array
    {
        $moment = Carbon::parse($on ?? Carbon::now());

        /** @var list<AccessGrant> $rows */
        $rows = AccessGrant::query()
            ->where('owner_type', $principal->getMorphClass())
            ->where('owner_id', $principal->getKey())
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return [];
        }

        $byContent = $this->groupByContent($rows);

        // One batched catalog call for every distinct work, and one subscription read for the whole set —
        // resolved BEFORE the loop, which is the entire difference between this and an N+1.
        $availability = $this->availabilityOf(
            array_values(array_map(
                static fn (array $group): ContentReference => $group['content'],
                $byContent,
            )),
            $moment,
        );

        $subscriptions = $this->subscriptions->grantsFor($principal, $moment);

        $decisions = [];

        foreach ($byContent as $key => $group) {
            $row = $group['rows'][0];
            $strongest = $this->strongestGrant($group['rows'], $moment) ?? $row;

            $decisions[$key] = new OwnedWork(
                $group['content'],
                $this->decide($group['content'], $group['rows'], $subscriptions, $availability[$key], $moment),
                $strongest->source,
                $strongest->acquired_at,
                $strongest->expires_at,
                $strongest->bundle_ref,
                // Provenance, never the seller. Rebuilt from the stored morph rather than the sentinel, so a
                // platform sale carries no merchant at all instead of a scope that renders as one.
                $strongest->merchant_type === null || $strongest->merchant_id === null
                    ? null
                    : new MerchantScope($strongest->merchant_type, $strongest->merchant_id),
            );
        }

        return $decisions;
    }

    /**
     * The union, applied to one work: ownership first, then the live subscription view, then no.
     *
     * The single place both entry points decide, so `accessFor` and `grantsFor` cannot answer the same
     * question differently — which is what would happen if each did its own union, and it would show up as a
     * library row disagreeing with the download page it links to.
     *
     * @param  list<AccessGrant>  $rows
     * @param  array<string, SubscriptionGrant>  $subscriptions
     */
    private function decide(ContentReference $content, array $rows, array $subscriptions, ContentAvailability $availability, CarbonInterface $moment): AccessDecision
    {
        $grant = $this->strongestGrant($rows, $moment);

        if ($grant instanceof AccessGrant) {
            return $this->decisionFromGrant($grant, $availability, $moment);
        }

        // Nothing owned, or a revoked or expired row whose owner also holds a subscription that reaches the
        // work: still granted, and the reason is now the subscription. Skipping this would show a refunded
        // purchase as locked to somebody whose plan covers it anyway.
        if ($this->coveredBy($subscriptions, $content, $moment)) {
            return new AccessDecision(true, AccessVia::Subscription, VersionResolution::latest(), $availability);
        }

        // The availability is carried through even here, so a consumer can tell "you do not own this" from
        // "this does not exist" without a second lookup.
        return AccessDecision::denied($availability);
    }

    /**
     * The rows for one work — plural, because the register allows the same person to own the same reference
     * through two different merchants, and answering from an arbitrary one of them would be a coin flip.
     *
     * @return list<AccessGrant>
     */
    private function grantRowsFor(Model $principal, ContentReference $content): array
    {
        /** @var list<AccessGrant> $rows */
        $rows = AccessGrant::query()
            ->where('owner_type', $principal->getMorphClass())
            ->where('owner_id', $principal->getKey())
            ->where('content_type', $content->type)
            ->where('content_ref', $content->reference)
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * The grant to answer with, or null when none of them grants at the moment.
     *
     * Among granting rows a PERMANENT one wins over a windowed one, and among equals the earliest acquired.
     * Both halves matter: reporting the rental when somebody also owns the work outright would show an end
     * date that does not apply to them, and an unordered pick would make the same call answer differently on
     * two engines whose natural row order differs.
     *
     * @param  list<AccessGrant>  $rows
     */
    private function strongestGrant(array $rows, CarbonInterface $moment): ?AccessGrant
    {
        $granting = array_values(array_filter(
            $rows,
            static fn (AccessGrant $row): bool => $row->grantsAt(Carbon::parse($moment)),
        ));

        if ($granting === []) {
            return null;
        }

        usort($granting, static function (AccessGrant $a, AccessGrant $b): int {
            $permanence = ($a->expires_at === null ? 0 : 1) <=> ($b->expires_at === null ? 0 : 1);

            return $permanence !== 0 ? $permanence : $a->acquired_at->getTimestamp() <=> $b->acquired_at->getTimestamp();
        });

        return $granting[0];
    }

    /**
     * The decision for a granting row, with the version RESOLVED rather than described.
     *
     * The grant carries the promise ("updates for a year"); a delivery path needs today's instruction ("hand
     * over the newest one" / "the newest one from before this date"). Resolving here is what keeps that rule
     * in one place instead of in every consumer that renders a download button.
     */
    private function decisionFromGrant(AccessGrant $grant, ContentAvailability $availability, CarbonInterface $moment): AccessDecision
    {
        return new AccessDecision(
            true,
            AccessVia::fromGrantSource($grant->source),
            $this->policies->resolutionFor($grant, $moment),
            $availability,
        );
    }

    /**
     * Whether any of the person's subscriptions reaches the work at the moment.
     *
     * The window is checked HERE rather than trusted from the reader: `grantsFor` answers with the grants
     * that grant access, and `coversInstant` is the separate question of whether the instant asked about
     * falls inside the recorded period. A back-dated access check must not be answered with today's state.
     *
     * @param  array<string, SubscriptionGrant>  $subscriptions
     */
    private function coveredBy(array $subscriptions, ContentReference $content, CarbonInterface $moment): bool
    {
        return array_any($subscriptions, fn (SubscriptionGrant $subscription): bool => $subscription->coversInstant($moment) && $this->scope->covers($subscription, $content));
    }

    /**
     * Availability for a set of works, with every asked-for key present.
     *
     * A key the catalog omits is read as gone: the register says somebody owns it and the consumer's own
     * catalog has never heard of it, which from a reader's side is exactly what a taken-down work looks like.
     * Defaulting it to available instead would turn a stale reference into a broken download with no
     * explanation anywhere.
     *
     * @param  list<ContentReference>  $references
     * @return array<string, ContentAvailability>
     */
    private function availabilityOf(array $references, CarbonInterface $moment): array
    {
        $reported = $this->catalog->availabilityOf($references, $moment);

        $availability = [];

        foreach ($references as $reference) {
            $availability[$reference->key()] = $reported[$reference->key()] ?? ContentAvailability::ContentGone;
        }

        return $availability;
    }

    /**
     * The rows gathered per work, keyed by the content key.
     *
     * One pass rather than a filter per work: the same person may hold several rows for one reference — one
     * per merchant — and re-scanning the whole set for each of them would turn a library read into quadratic
     * work in the exact place the acceptance criterion is about.
     *
     * @param  list<AccessGrant>  $rows
     * @return array<string, array{content: ContentReference, rows: list<AccessGrant>}>
     */
    private function groupByContent(array $rows): array
    {
        $byContent = [];

        foreach ($rows as $row) {
            $content = new ContentReference($row->content_type, $row->content_ref);

            $byContent[$content->key()] ??= ['content' => $content, 'rows' => []];
            $byContent[$content->key()]['rows'][] = $row;
        }

        return $byContent;
    }
}
