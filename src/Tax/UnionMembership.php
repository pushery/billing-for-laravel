<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

/**
 * Whether an ISO-3166 country is a member of the EU VAT area — the one fact that decides, for a reverse-charge
 * input, whether it books to the §13b Abs. 1 (EU) or §13b Abs. 2 (third-country) account.
 *
 * This is an IDENTITY list of the 27 member states, not a market list: it says nothing about where a platform
 * chooses to sell (that is the configurable allowlist). It is the same membership the OSS rate table encodes,
 * lifted here so a caller that needs only "is this the union?" — the DATEV account choice for a foreign
 * creator's supply — does not reach into a rate calculator for it.
 */
final class UnionMembership
{
    /**
     * The day this membership was last checked against the published list.
     *
     * Membership is a political fact with a date on it, and a list without one cannot report that it has
     * aged. The failure is silent in both directions: a country that left keeps being treated as internal,
     * one that joined keeps being treated as foreign — and in both cases the sale lands in a return written
     * for a different population, which makes the return wrong as a whole rather than wrong by a number.
     *
     * It moves on a different clock from the rate table — the last change was a departure in 2020 — so this
     * is REPORTED and never turned into a failure. An error that would be red for a decade at a stretch is
     * an error an operator learns to scroll past, and then it is not there for the year it matters.
     *
     * `DefinesUnionMembership::unionMembersValidFrom()` is the same fact for a consumer who supplies their
     * own union; this is the shipped answer, so that the shipped answer has an age too.
     */
    public const string MEMBERS_CHECKED_ON = '2026-07-26';

    /** @var list<string> the 27 EU member states, ISO-3166-1 alpha-2 */
    private const array MEMBERS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /** Whether the given country (case-insensitive) is an EU member state. An empty or unknown code is not. */
    public static function isMember(?string $country): bool
    {
        return $country !== null && in_array(strtoupper($country), self::MEMBERS, true);
    }

    /**
     * The membership as a list, for a caller that needs to hand it somewhere rather than ask about one code.
     *
     * Exposed so the shipped answer has exactly one home. A second copy of these 27 codes elsewhere would be
     * a second thing to update when membership changes — and the copy nobody updated is the one that decides
     * whether a sale belongs in a return.
     *
     * @return list<string>
     */
    public static function members(): array
    {
        return self::MEMBERS;
    }
}
