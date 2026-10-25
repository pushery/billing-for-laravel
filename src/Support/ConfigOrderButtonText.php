<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\OrderButtonText;

/**
 * The text beside the order button, read from `billing.checkout.submit_message`.
 *
 * Each entry under `tiers` and under `addons` is a translation key or the text itself. A key the translator knows,
 * a JSON string included, is read in the locale of the request that opens the checkout, which is the language the
 * application serves the buyer in. Anything else goes out as written. A tier or an add-on without an entry, and an
 * entry that is blank, add no text.
 */
final readonly class ConfigOrderButtonText implements OrderButtonText
{
    public function __construct(private Repository $config) {}

    public function forSubscription(Model $billable, string $tierKey): ?string
    {
        return $this->text('billing.checkout.submit_message.tiers', $tierKey);
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
        $entry = is_array($entries) ? ($entries[$key] ?? null) : null;

        if (! is_string($entry) || trim($entry) === '') {
            return null;
        }

        $text = Lang::has($entry) ? Lang::get($entry) : $entry;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
