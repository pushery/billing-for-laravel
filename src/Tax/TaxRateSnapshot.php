<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use JsonException;
use Pushery\Billing\Exceptions\TaxRateSnapshotTampered;

/**
 * The shipped rates as a versioned data file with a header and a hash over its contents.
 *
 * ## Why this is a file and not a constant
 *
 * A `private const array` cannot say where it came from, when it was checked, or which edition of a source
 * it reflects. That is not a cosmetic gap: two rates stood wrong for a year precisely because nothing about
 * them could be questioned. A file carries a header — source, URL, fetch time, the window the source said it
 * was answering for, and who accepted it — so every one of those questions has an answer in the same place
 * as the numbers.
 *
 * ## The snapshot is the source on the money path. No network, ever.
 *
 * An invoice is written from this file and from nothing else. An offline installation must be able to
 * invoice; a package that needs the internet to do it is broken. Fetching is a separate, deliberate act that
 * happens somewhere else and never touches this file.
 *
 * ## What the hash is actually for
 *
 * Not integrity in transit — Composer already covers that. It is for the edit nobody sees: a digit changed
 * inside `vendor/`, which appears in no diff because `vendor/` is in no diff. That change would silently
 * reprice every invoice to a country, and the only trace would be the money. The hash makes it loud.
 *
 * The digest is taken over a **canonical** rendering of the rates alone — keys sorted, no whitespace, header
 * excluded. Excluding the header is deliberate: re-recording who approved a table must not require
 * recomputing a digest over numbers nobody touched, or the two would drift and the hash would start being
 * "fixed" rather than checked.
 */
final readonly class TaxRateSnapshot
{
    /**
     * @param  array<string, int>  $rates  country → standard rate in basis points
     * @param  array<string, string>  $header  source, url, fetched_at, situation_on, approved_by
     */
    private function __construct(
        public array $rates,
        public array $header,
    ) {}

    /**
     * Load a snapshot and verify it has not been edited since it was recorded.
     *
     * @throws TaxRateSnapshotTampered when the file is unreadable, malformed, or its digest disagrees
     */
    public static function load(string $path): self
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            throw TaxRateSnapshotTampered::unreadable($path);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TaxRateSnapshotTampered::unreadable($path);
        }

        $rates = $decoded['rates'] ?? null;
        $recorded = $decoded['sha256'] ?? null;

        if (! is_array($rates) || ! is_string($recorded)) {
            throw TaxRateSnapshotTampered::unreadable($path);
        }

        /** @var array<string, int> $rates */
        $actual = self::digestOf($rates);

        if (! hash_equals($recorded, $actual)) {
            throw TaxRateSnapshotTampered::digestMismatch($path, $recorded, $actual);
        }

        /** @var array<string, string> $header */
        $header = array_filter(
            $decoded,
            static fn (string $key): bool => ! in_array($key, ['rates', 'sha256'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return new self($rates, $header);
    }

    /**
     * The digest of a rate set.
     *
     * Canonical means: keys sorted and the JSON rendered without whitespace. Without sorting, two files with
     * identical numbers in a different order would disagree — and a hash that depends on key order tells you
     * about the editor, not about the data.
     *
     * @param  array<string, int>  $rates
     */
    public static function digestOf(array $rates): string
    {
        ksort($rates);

        return hash('sha256', json_encode($rates, JSON_THROW_ON_ERROR));
    }
}
