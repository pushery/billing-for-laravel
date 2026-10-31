<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\OrderButtonText;
use Pushery\Billing\Enums\BillingInterval;

/**
 * The text beside the order button, read from `billing.checkout.submit_message`.
 *
 * Each entry under `tiers` and under `addons` is a translation key or the text itself, and an entry under `tiers` may
 * be a list of them keyed by interval, for a tier sold at more than one. A key the translator knows,
 * a JSON string included, is read in the locale of the request that opens the checkout, which is the language the
 * application serves the buyer in. Anything else goes out as written. A tier or an add-on without an entry, and an
 * entry that is blank, add no text.
 */
final readonly class ConfigOrderButtonText implements OrderButtonText
{
    public function __construct(private Repository $config) {}

    /**
     * A text names the tier at its default interval. A list keyed by interval names one text for each, so a yearly
     * checkout states the yearly price; an interval the list does not name, and any interval but the default one
     * where the entry is a single text, adds no text rather than the price of another billing period.
     */
    public function forSubscription(Model $billable, string $tierKey, ?BillingInterval $interval = null): ?string
    {
        $entries = $this->config->get('billing.checkout.submit_message.tiers');
        $entry = is_array($entries) ? ($entries[$tierKey] ?? null) : null;
        $default = $this->defaultInterval($tierKey);

        if (is_array($entry)) {
            return $this->translated($entry[($interval ?? $default)->value] ?? null);
        }

        return ! $interval instanceof BillingInterval || $interval === $default ? $this->translated($entry) : null;
    }

    public function forAddon(Model $billable, string $addonKey): ?string
    {
        return $this->text('billing.checkout.submit_message.addons', $addonKey);
    }

    private function text(string $map, string $key): ?string
    {
        // Read as a map and indexed by the key, never through a dotted config path: a tier key may carry a dot, and
        // the path would read it as one more level.
        $entries = $this->config->get($map);

        return $this->translated(is_array($entries) ? ($entries[$key] ?? null) : null);
    }

    /** The tier's own interval, which its single text describes: `billing.tiers.<key>.interval`, a month without one. */
    private function defaultInterval(string $tierKey): BillingInterval
    {
        $configured = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'interval');

        return (is_string($configured) ? BillingInterval::tryFrom($configured) : null) ?? BillingInterval::Month;
    }

    /** An entry as the text it stands for: a translation key in the request's locale, else the text as written. */
    private function translated(mixed $entry): ?string
    {
        if (! is_string($entry) || trim($entry) === '') {
            return null;
        }

        $text = Lang::has($entry) ? Lang::get($entry) : $entry;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
