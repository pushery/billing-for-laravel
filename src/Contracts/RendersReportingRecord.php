<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\SellerPeriodReport;

/**
 * Turns a period's seller reports into the bytes an authority is given.
 *
 * ## Why this is a contract rather than a method
 *
 * Which format a duty is reported in is a jurisdiction's answer, not the package's. A consumer under
 * another duty registers their own renderer — their own format, their own deadline, their own recipient —
 * and must not have to switch off a German one that was never theirs to begin with. That is the same reason
 * the field catalog lives on {@see ReportingProfile} rather than in the core.
 *
 * No shipped renderer will ever be a payment-settlement return, and this contract is not the way to get one
 * into the package: that filing is the payment provider's duty as the settlement entity, not the platform's,
 * and `UsRegimeBoundaryTest` holds the line by scanning `src/` for it. A consumer who has been told they owe
 * one binds it here, which is exactly the right place for a duty the package does not have.
 *
 * ## The version is data, not a comment
 *
 * A record that does not say which version of a format it was built to is unverifiable years later, and the
 * version moves without the data moving. So it is asked for here and stored beside the bytes, rather than
 * living in a docblock that ships with the code and not with the artifact.
 *
 * ## Determinism is part of the contract
 *
 * Two runs over the same reports must produce the same BYTES, because that is what the archive compares. It
 * is easy to break by accident — an unordered iteration, a locale-dependent number, a timestamp inside the
 * payload — and each of those turns "did anything change since we last produced this?" into a question
 * nobody can answer.
 */
interface RendersReportingRecord
{
    /** A short, stable name for the format — stored beside the bytes and used to select runs. */
    public function format(): string;

    /** Which version of that format these bytes are built to. */
    public function version(): string;

    /**
     * @param  list<SellerPeriodReport>  $reports  every seller in the period, in the run's own order
     * @param  array<string, array<string, mixed>>  $records  the sellers' own values, keyed by `type#id` —
     *                                                        supplied by the caller because the package does
     *                                                        not store seller master data
     */
    public function render(int $year, string $currency, array $reports, array $records): string;
}
