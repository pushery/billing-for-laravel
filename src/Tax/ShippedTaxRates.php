<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Exceptions\TaxRateSnapshotTampered;

/**
 * The rates the money path actually prices from, loaded from the shipped, digest-checked snapshot.
 *
 * ## Why this class exists at all
 *
 * The snapshot file and its hash were built to make one specific attack loud: a digit changed inside
 * `vendor/`, which appears in no diff because `vendor/` is in no diff, silently repricing every invoice to a
 * country. The shipped documentation says so in as many words — *"pricing stops rather than falling back to
 * whatever is in the file"*, *"the file is the source every invoice is priced from"*.
 *
 * That promise holds only if the money path prices from the file. A calculator pricing from a copy of the
 * rates beside it would leave the digest guard real, tested and unreached: a tampered snapshot would reprice
 * nothing and stop nothing, and the reader would hold a protection promise they did not have, against the
 * one edit that leaves no trace but the money. This class is the half that makes the promise true.
 *
 * ## Two copies of regulated numbers is the deeper problem
 *
 * A lockstep test holding a constant and the file equal proves today's agreement and nothing about
 * tomorrow's. Two copies of the same regulated figures drift; the only question is when, and the symptom is
 * an invoice priced from whichever one the reader was not looking at. There is one copy.
 *
 * ## Loading
 *
 * Once per boot, not once per invoice. The provider binds this as a container singleton and the factory
 * injects it, so the file is read when the application boots and never again. There is no network anywhere
 * near this — the file ships inside the package, so an offline installation prices exactly as an online one.
 */
final readonly class ShippedTaxRates
{
    /**
     * @param  array<string, int>  $bps  country → standard rate in basis points
     * @param  array<string, string>  $header  source, url, fetched_at, situation_on, approved_by
     */
    private function __construct(
        public array $bps,
        public array $header,
    ) {}

    /**
     * The snapshot this package ships.
     *
     * @throws TaxRateSnapshotTampered when the file is unreadable, malformed, or its digest disagrees
     */
    public static function shipped(): self
    {
        return self::fromPath(self::shippedPath());
    }

    /**
     * A snapshot at an explicit path.
     *
     * The seam a test needs, and it is a seam rather than a mutable global on purpose: proving that a
     * tampered file stops pricing must not mean editing the repository's own shipped file. A test that
     * writes into the real tree can destroy work and still report green, which is a worse failure than the
     * one being tested for.
     *
     * @throws TaxRateSnapshotTampered when the file is unreadable, malformed, or its digest disagrees
     */
    public static function fromPath(string $path): self
    {
        $snapshot = TaxRateSnapshot::load($path);

        return new self($snapshot->rates, $snapshot->header);
    }

    /** Where the shipped snapshot lives, resolved from this file so an install path never has to be guessed. */
    public static function shippedPath(): string
    {
        return dirname(__DIR__, 2).'/resources/tax-rates/eu-2026-07-25.json';
    }

    /**
     * The day these rates were last checked against what each member state publishes.
     *
     * Read from the file's own header rather than kept as a constant beside it. A date and the numbers it
     * describes are one fact; held apart, the date is the half that gets forgotten, and a table whose stated
     * age is wrong is more dangerous than one with no date at all — it answers the staleness question
     * confidently and incorrectly.
     */
    public function checkedOn(): string
    {
        $situation = $this->header['situation_on'] ?? null;

        return is_string($situation) && $situation !== '' ? $situation : '';
    }
}
