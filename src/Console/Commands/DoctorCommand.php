<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Consumer\GermanWithdrawalPolicy;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;
use Pushery\Billing\Contracts\DefinesUnionMembership;
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Contracts\SuppliesTaxRates;
use Pushery\Billing\Contracts\TaxDisclosurePolicy;
use Pushery\Billing\Drivers\Stripe\StripeServiceProvider;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Marketplace\GermanTaxDisclosurePolicy;
use Pushery\Billing\Models\ExchangeRateRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Pushery\Billing\Preflight\Profiles\GermanProductTaxonomy;
use Pushery\Billing\Preflight\Profiles\GermanReportingProfile;
use Pushery\Billing\Tax\DatabaseExchangeRateSource;
use Pushery\Billing\Tax\DistanceSaleThresholdMonitor;
use Pushery\Billing\Tax\ShippedTaxRates;
use Pushery\Billing\Tax\TaxCalculatorFactory;
use Pushery\Billing\Tax\TaxRateMatrix;
use Pushery\Billing\Tax\UnionMembership;
use Pushery\Billing\ValueObjects\ContentReference;
use Stripe\StripeClient;
use Throwable;

/**
 * Checks that the Stripe API version the package is pinned to matches what the provider will actually render
 * webhook payloads in — because the pin alone is not enough.
 *
 * The package pins the version it SENDS on outbound calls (see StripeServiceProvider). But a webhook payload
 * is rendered in the version of the ENDPOINT that receives it (or, when the endpoint has none, the account's
 * default) — a setting that lives at Stripe, not in this codebase. So an endpoint pinned to an older version,
 * or left on the account default while the account drifts, delivers payloads in a shape the mapper was not
 * written for. The mapper reads fields defensively, so the failure is silent: a real billing event just
 * stops firing. This surfaces that drift as an operator- and CI-visible signal (a non-zero exit on mismatch).
 */
final class DoctorCommand extends Command
{
    protected $signature = 'billing:doctor';

    protected $description = 'Check that Stripe webhook endpoints render payloads in the pinned API version';

    /** How old a configured rate table may be before the diagnostic calls it out. */
    private const int RATE_TABLE_MAX_AGE_DAYS = 180;

    /**
     * How old an imported exchange-rate series may be before the diagnostic calls it out.
     *
     * Three days rather than the fourteen at which the money path breaks: two missed daily imports plus a
     * weekend. A limit set at the breaking point reports the incident instead of preventing it.
     */
    private const int RATE_SERIES_MAX_AGE_DAYS = 3;

    public function __construct(private readonly CheckpointRegistry $profiles)
    {
        parent::__construct();
    }

    /** The jurisdiction whose readings ship in the box. */
    private const string SHIPPED_PROFILE = 'de';

    /**
     * The contracts a jurisdiction answers, mapped to the implementation that ships as the default.
     *
     * Listed rather than discovered because the point is the DEFAULT, and a default is a decision somebody
     * made in the service provider — there is nothing in the container to read it back from. A contract added
     * to the provider without a line here is invisible to this check; that is the known cost of the list, and
     * the reason it sits next to the bindings it mirrors rather than in a config file.
     */
    private const array PROFILE_READINGS = [
        ProductTaxonomy::class => GermanProductTaxonomy::class,
        ReportingProfile::class => GermanReportingProfile::class,
        TaxDisclosurePolicy::class => GermanTaxDisclosurePolicy::class,
        ConsumerWithdrawalPolicy::class => GermanWithdrawalPolicy::class,
    ];

    public function handle(Repository $config, StripeClient $stripe, AddonCatalog $addons, AddonContentMap $works, DistanceSaleThresholdMonitor $thresholds): int
    {
        if (! (bool) $config->get('billing.enabled', true)) {
            $this->components->info('Billing is disabled; nothing to check.');

            return self::SUCCESS;
        }

        // One running verdict rather than a code recomposed at each exit. It used to be the latter, and the
        // Stripe-unreachable exit was assembled from a SUBSET of the findings — so an aged rate table failed
        // the command while Stripe answered and passed it while Stripe was down. That is the direction that
        // hides: a green doctor during an outage reads as "the tax data is fine".
        //
        // A finding does not stop being true because a later check could not run, so nothing here ever
        // subtracts from $failing.
        $failing = $this->reportRateTableAge($config);

        $this->reportUnionMembershipAge();

        $this->reportDistanceSaleThresholdAge($thresholds);

        $failing = $this->reportExchangeRateSeriesAge($config) || $failing;

        $this->reportProfileInheritance();

        $failing = $this->reportWorksTheProfileDoesNotCover($config, $addons, $works) || $failing;

        $pinned = $this->pinnedVersion($config);

        $this->components->info("The package is pinned to Stripe API version {$pinned}.");

        try {
            $endpoints = $stripe->webhookEndpoints->all(['limit' => 100]);
        } catch (Throwable $e) {
            // A diagnostic never fails the app because it could not reach the provider; it reports and stops.
            $this->components->warn('Could not read the Stripe webhook endpoints: '.$e->getMessage());

            return $this->verdict($failing);
        }

        $drift = 0;
        $checked = 0;

        foreach ($endpoints->data as $endpoint) {
            $checked++;
            $version = is_string($endpoint->api_version) ? $endpoint->api_version : null;
            $url = $endpoint->url !== '' ? $endpoint->url : $endpoint->id;

            if ($version === null) {
                // No pinned version on the endpoint: its payloads follow the ACCOUNT's default version, which
                // can move under you. Pin it to match, or a later account-wide bump silently changes the shape.
                $this->components->warn("{$url} has no pinned version; it follows the account default. Pin it to {$pinned}.");
                $drift++;

                continue;
            }

            if ($version !== $pinned) {
                $this->components->error("{$url} renders payloads in {$version}, but the package expects {$pinned}.");
                $drift++;

                continue;
            }

            $this->components->info("{$url} matches.");
        }

        if ($checked === 0) {
            $this->components->warn('No webhook endpoints are configured at Stripe.');

            return $this->verdict($failing);
        }

        if ($drift > 0) {
            $this->components->error("{$drift} of {$checked} webhook endpoint(s) do not match the pinned version.");

            return $this->verdict(true);
        }

        $this->components->info("All {$checked} webhook endpoint(s) render payloads in the pinned version.");

        return $this->verdict($failing);
    }

    /**
     * The single place a finding becomes an exit code.
     *
     * Worth a method rather than a repeated ternary: with the expression written out at each exit, adding a
     * sixth check means remembering to widen five of them, and forgetting one is invisible — the command
     * still prints the warning, it just stops counting it.
     */
    private function verdict(bool $failing): int
    {
        return $failing ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report every work an active consumer-rights profile does not actually cover.
     *
     * The withdrawal gate needs TWO conditions and reads like it needs one. `billing.consumer_rights.profile`
     * arms it; a **classified archetype** on the work is what gives it something to act on. `ContentGrants`
     * returns on a null type before the profile is ever read, so a work nobody classified is provided with no
     * consent recorded — no exception, no log line, and the same profile refusing the purchase next to it.
     *
     * That is money rather than tidiness: without the recorded double declaration the buyer's withdrawal
     * right does not extinguish, so a refund inside the window is owed rather than granted, and the platform
     * is seller of record.
     *
     * It is reported here rather than refused at runtime because refusing would break every install that set
     * a profile and never classified anything, which is a decision somebody has to make deliberately. What
     * this command can do without making it is leave the gap impossible to miss.
     *
     * Read from `billing.addons`, which is where the `archetype` key is forgotten. A catalog that is not
     * config-driven answers for keys this loop never sees, and the closing line says how many were examined
     * so the silence is never mistaken for a clean bill.
     */
    private function reportWorksTheProfileDoesNotCover(Repository $config, AddonCatalog $catalog, AddonContentMap $works): bool
    {
        if ($config->get('billing.consumer_rights.profile') === null) {
            return false; // no profile, no gate, nothing to be uncovered BY
        }

        if (! (bool) $config->get('billing.content_ownership.enabled', false)) {
            return false; // nothing is provided through the grant path, so nothing passes the gate
        }

        if (! $catalog instanceof SuppliesProductArchetypes) {
            $this->components->warn(
                'The consumer-rights profile is set, but the add-on catalog cannot classify anything '
                .'(it does not implement SuppliesProductArchetypes), so the withdrawal gate never fires.'
            );

            return true;
        }

        $addons = $config->get('billing.addons', []);
        $addons = is_array($addons) ? $addons : [];
        $uncovered = [];

        foreach (array_keys($addons) as $key) {
            $key = is_string($key) ? $key : (string) $key;

            if (! $works->contentFor($key) instanceof ContentReference) {
                continue; // not a work — a credit pack has no withdrawal right to extinguish
            }

            if (! $catalog->archetypeFor($key) instanceof TaxArchetype) {
                $uncovered[] = $key;
            }
        }

        if ($uncovered === []) {
            $this->components->info(
                'The consumer-rights profile covers every configured work ('.count($addons).' add-on(s) examined).'
            );

            return false;
        }

        foreach ($uncovered as $key) {
            $this->components->error(
                "Add-on '{$key}' hands over a work but carries no archetype, so the consumer-rights profile "
                .'does not gate it: it is provided without a recorded withdrawal consent.'
            );
        }

        return true;
    }

    /**
     * Report the jurisdiction readings an install inherited without choosing them.
     *
     * There are two ways to make this package answer for a jurisdiction, and they do not talk to each other.
     * One is `billing.tax_profile`. The other is binding your own implementation of a contract in the
     * container. Set the first and forget the second and the install is a HYBRID: a foreign profile deciding
     * some questions while the shipped country's reading decides the rest — a valid-looking object with two
     * jurisdictions inside it, and nothing anywhere saying so.
     *
     * That is the failure this reports. Not "you must replace these" — the extension path is deliberately
     * the developer's, and inheriting a reading can be a perfectly deliberate choice. The failure is
     * inheriting one WITHOUT KNOWING, because a silent hybrid produces documents that are wrong in a way
     * only a specialist reads.
     *
     * Phrased against the shipped default rather than against a country name, so it keeps working when
     * another profile ships: the question is whether a binding is still the one that came in the box while
     * the profile is not.
     */
    private function reportProfileInheritance(): void
    {
        // Read from the RESOLVED profile, never from the config string. `billing.tax_profile` only accepts a
        // key the package ships — an unknown one is refused outright — so a foreign jurisdiction never arrives
        // that way. It arrives as a container binding of `JurisdictionProfile`, which takes precedence over
        // the config entirely. A check written against the string would have been watching a door nobody uses.
        $profile = $this->laravel->make(CheckpointRegistry::class)->profile();

        if (! $profile instanceof JurisdictionProfile || $profile->key() === self::SHIPPED_PROFILE) {
            return;
        }

        $active = $profile->key();

        $inherited = $this->inheritedReadings();

        if ($inherited === []) {
            return;
        }

        // A warning, not an error. The package cannot know that a reading is wrong for a jurisdiction — only
        // that nobody said it was right. Failing the command here would make an honest, deliberate reuse
        // indistinguishable from an oversight, and an operator who cannot silence a check learns to ignore it.
        $this->components->warn(
            "The [{$active}] jurisdiction profile is active, but ".count($inherited).' reading(s) are still the '
            .'shipped ['.self::SHIPPED_PROFILE.'] ones. A profile that answers some questions and not others '
            .'is a hybrid, and nothing on a document says which half decided it.'
        );

        // One per line rather than folded into the sentence above. The console component wraps a long line at
        // the terminal width, and a name broken across a wrap is a name an operator cannot search for — which
        // is the first thing anyone does with a list like this. Found the hard way: the assertion for the
        // headline passed while the assertion for the names failed, from the same single message.
        foreach ($inherited as $reading) {
            $this->line("  - {$reading}");
        }

        $this->components->info('Bind your own for each, or confirm it is right for your jurisdiction.');
    }

    /**
     * Which shipped readings are still bound, by short contract name.
     *
     * Its own method with a declared return type on purpose. Resolved inline, static analysis follows the
     * service provider's bindings and concludes the comparison is always true — which is correct for a
     * DEFAULT container and wrong for the only container that matters here, the one a consumer has rebound.
     * The boundary is where that difference belongs: the caller reasons about a list, and what the container
     * actually returns is a runtime question.
     *
     * @return list<string>
     */
    private function inheritedReadings(): array
    {
        $inherited = [];

        foreach (self::PROFILE_READINGS as $contract => $shipped) {
            if ($this->laravel->make($contract)::class === $shipped) {
                $inherited[] = class_basename($contract);
            }
        }

        return $inherited;
    }

    /**
     * Report how old the configured rate table is, and say so loudly once it is past its allowed age.
     *
     * A rate is a property of a country at a moment, and countries move theirs. A table with no expiry goes
     * on answering with the confidence of the day it was written, and the symptom is an invoice that is
     * merely wrong rather than one that errors. This is a diagnostic rather than a boot guard on purpose:
     * refusing to boot because a table aged would take an application down over something that is still
     * mostly right, which is the wrong trade for a number nobody can repair at 3 a.m.
     *
     * @return bool whether the table is past its allowed age
     */
    private function reportRateTableAge(Repository $config): bool
    {
        [$source, $validFrom] = $this->answeringRateTable($config);

        $maxAge = $config->get('billing.tax_matrix.max_age_days', self::RATE_TABLE_MAX_AGE_DAYS);
        $maxAge = is_int($maxAge) ? $maxAge : self::RATE_TABLE_MAX_AGE_DAYS;

        $age = (int) Carbon::parse($validFrom)->diffInDays(Carbon::now(), absolute: false);

        if ($age > $maxAge) {
            $this->components->error(
                "The {$source} tax rate table is {$age} days old (limit {$maxAge}). Rates move; verify it "
                .'against the current published rates and refresh its date.'
            );

            return true;
        }

        $this->components->info("The {$source} tax rate table is {$age} days old (limit {$maxAge}).");

        return false;
    }

    /**
     * Which rate table would actually answer a sale, and the day it was last known good.
     *
     * The point of asking it this way: this check used to read only the CONFIGURED table and return in
     * silence when there was none — which is the default. So the one installation that runs entirely on the
     * package's own shipped rates was the one installation the age check never spoke about, and two of those
     * rates drifted by two points for a year before anybody noticed.
     *
     * Silence about age is the failure. There is always a table answering, so there is always an age to
     * report, and this returns the age of the table that would really be used rather than of the one a
     * reader might assume.
     *
     * @return array{string, string} [what it is, the date it was last known good]
     */
    private function answeringRateTable(Repository $config): array
    {
        // The DATE comes from the table the factory actually built, not from a second reading of the
        // settings it was built from. Those two agreed — but only because this method reproduced the
        // factory's precedence by hand, in another file, where a third source added there would never
        // arrive here. The failure that produces is the one this command exists to prevent: an operator
        // told the age of a table that is not the one being calculated with.
        //
        // Only the LABEL is still derived here, because a matrix does not carry where it came from and
        // giving it one would put a diagnostic's vocabulary into the calculation path.
        $answering = Container::getInstance()->make(TaxCalculatorFactory::class)->answeringRateMatrix();

        if ($answering instanceof TaxRateMatrix) {
            $matrix = $config->get('billing.tax_matrix');
            $profile = $this->profiles->profile();

            $label = is_array($matrix) && is_string($matrix['valid_from'] ?? null)
                ? 'configured'
                : ($profile instanceof SuppliesTaxRates ? "profile ({$profile->key()})" : 'loaded');

            return [$label, $answering->validFromDate()];
        }

        // Null means no configured table and no profile supplying one, so the shipped snapshot answers —
        // and it now reports the date from the same file the money path prices from, rather than from a
        // constant beside it. The age and the numbers were two facts held apart; the date was the half that
        // could go quietly wrong, and a table whose stated age is wrong answers the staleness question
        // confidently and incorrectly.
        return ['shipped', ShippedTaxRates::shipped()->checkedOn()];
    }

    /**
     * Report how old the union membership answering this install is.
     *
     * `DefinesUnionMembership` asks a profile for the day its membership was known correct, and until now
     * nothing ever asked — the method was a promise with no reader, which reads exactly like a promise being
     * kept. The shipped list had no date at all, so an operator running entirely on it could not answer "how
     * old is this" from anything but the git log.
     *
     * Reported, never failed. Membership changes about once a decade, so any age limit worth setting would
     * be red for years at a time, and a check that is always red is a check nobody reads on the day it
     * finally means something. The rate table is the opposite case and is treated the opposite way.
     */
    private function reportUnionMembershipAge(): void
    {
        $profile = $this->profiles->profile();

        // The profile answers before the shipped list does, so its date is the one describing what is really
        // in use. Reporting the shipped date under a foreign profile would state the age of a list that
        // decides nothing here.
        [$source, $validFrom] = $profile instanceof DefinesUnionMembership
            ? ["profile ({$profile->key()})", $profile->unionMembersValidFrom()->toDateString()]
            : ['shipped', UnionMembership::MEMBERS_CHECKED_ON];

        $age = (int) Carbon::parse($validFrom)->diffInDays(Carbon::now(), absolute: false);

        $this->components->info("The {$source} union membership was last checked {$age} days ago.");
    }

    /**
     * Report how old the imported exchange-rate series is, per configured currency.
     *
     * ## The failure this ends
     *
     * The import runs daily. When a publisher is down for one currency the command reports it, skips that
     * currency, and returns SUCCESS anyway — there is no error accumulator and the schedule entry has no
     * failure hook. Every exit-code monitor therefore sees green while a series quietly stops growing.
     *
     * Nothing read the newest `rate_date` anywhere in the package, so the first VISIBLE effect was an invoice
     * that could not be issued: the lookup walks forward at most `FORWARD_LIMIT_DAYS`, and past that it finds
     * no row and refuses the document. A warning that arrives then is not a warning, it is the incident.
     *
     * ## Why the limit is not simply the forward window
     *
     * 14 days is where it BREAKS. Reporting at the breaking point is the same as not reporting, so the
     * configured limit defaults well under it — three days is two missed daily imports plus a weekend. The
     * forward window is still the ceiling: a limit set above it would let the doctor stay green while the
     * money path is already refusing documents, so the effective limit is the lower of the two.
     *
     * Silent when the store is switched off: a single-currency install imports nothing and has nothing to age.
     */
    private function reportExchangeRateSeriesAge(Repository $config): bool
    {
        if (! (bool) $config->get('billing.tax_exchange_rates.enabled', false)) {
            return false;
        }

        $currencies = $config->get('billing.tax_exchange_rates.currencies', []);
        $currencies = is_array($currencies) ? array_values(array_filter($currencies, is_string(...))) : [];

        if ($currencies === []) {
            return false;
        }

        $configured = $config->get('billing.tax_exchange_rates.max_age_days', self::RATE_SERIES_MAX_AGE_DAYS);
        $limit = min(
            is_int($configured) && $configured > 0 ? $configured : self::RATE_SERIES_MAX_AGE_DAYS,
            DatabaseExchangeRateSource::FORWARD_LIMIT_DAYS,
        );

        $failing = false;

        foreach ($currencies as $currency) {
            // Matched on the TO side, which is the direction the config documents: the key lists the
            // currencies money is received in, and rates are stored as the publisher writes them — euro to
            // each of these — never turned around.
            $newest = ExchangeRateRecord::query()->where('to_currency', $currency)->max('rate_date');

            if (! is_string($newest) && ! $newest instanceof DateTimeInterface) {
                $this->components->error(
                    "No exchange rates have ever been imported for {$currency}. Run "
                    .'billing:exchange-rates:import — until a series exists, every conversion into this '
                    .'currency is refused rather than answered.'
                );

                $failing = true;

                continue;
            }

            $age = (int) Carbon::parse($newest)->diffInDays(Carbon::now(), absolute: false);

            if ($age > $limit) {
                $this->components->error(
                    "The {$currency} exchange-rate series is {$age} days old (limit {$limit}). The import is "
                    .'not keeping it current; past '.DatabaseExchangeRateSource::FORWARD_LIMIT_DAYS
                    .' days the lookup finds no row and a document cannot be issued.'
                );

                $failing = true;

                continue;
            }

            $this->components->info("The {$currency} exchange-rate series is {$age} days old (limit {$limit}).");
        }

        return $failing;
    }

    /**
     * Report how old the distance-sale threshold answering this install is.
     *
     * The fourth dated jurisdiction fact, and the one left out. Three of the four `*ValidFrom()` promises had
     * a reader; this one had none anywhere in the tree, while its own contract said the date exists "so its
     * age can be reported rather than assumed". An operator seeing the rate table's age and the union
     * membership's age reasonably concludes the dated facts are being watched — and this one was not.
     *
     * Reported, never failed, and with no shipped fallback: this package ships no limit of its own, so with
     * no profile supplying one there is genuinely no age to state. Whether a two-year-old date is a problem
     * depends on whether a legislator moved the number in the meantime, which the package cannot know.
     */
    private function reportDistanceSaleThresholdAge(DistanceSaleThresholdMonitor $monitor): void
    {
        $validFrom = $monitor->thresholdValidFrom();

        if (! $validFrom instanceof CarbonInterface) {
            $this->components->info('No profile supplies a distance-sale threshold, so there is no age to report (source: none).');

            return;
        }

        $age = (int) Carbon::parse($validFrom)->diffInDays(Carbon::now(), absolute: false);
        $source = $this->profiles->profile()?->key() ?? 'none';

        $this->components->info("The profile ({$source}) distance-sale threshold was last checked {$age} days ago.");
    }

    /** The version the package pins, honoring an app override, else the tested default. */
    private function pinnedVersion(Repository $config): string
    {
        $version = $config->get('billing.stripe.api_version');

        return is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION;
    }
}
