<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * A creator's tax standing, as it affects the INPUT side of a routed sale — the supply from them to the
 * platform.
 *
 * It never touches the output side. A personal attribute of one party in a chain applies to that party's
 * own link and no other, so the buyer's receipt carries the platform's full tax whatever this says. A
 * correction that reached a buyer's receipt would be a defect by definition, not a policy choice.
 *
 * The cases are named in the code's language; the legal vocabulary of any one country lives in that
 * country's profile and its translations, never in an identifier here. A consumer outside that country
 * runs the same enum and never reads a word of somebody else's tax law.
 *
 * `Unclarified` is the DEFAULT, and deliberately so: not knowing is a real state with real consequences,
 * and a creator whose status was never established must not silently be treated as the most convenient one.
 */
enum CreatorTaxStatus: string
{
    /** Established locally, charging tax normally. */
    case DomesticStandardRated = 'de_standard_rated';

    /**
     * Established locally and taxable from now on, but not yet confirmed by the registry.
     *
     * This case exists to resolve a collision, not to describe a nuance. A creator who crosses the
     * small-business threshold becomes taxable on the very transaction that crossed it — while the hardest
     * guard in the system permits a document to STATE tax only for a creator the registry has confirmed.
     * A creator who was a small business until that moment typically holds no registration at all, and
     * obtaining one takes weeks.
     *
     * All three obvious answers break something. Letting the crossing write its own confirmation makes
     * "confirmed" an empty word and the guard bypassable by arithmetic. Letting the guard win issues a
     * tax-free document for a transaction the law says is taxable. Holding the creator punishes somebody
     * who did nothing wrong, and it borrows the fail-safe meant for a standing nobody has established.
     *
     * So the transition gets its own state, and it separates two things that were being conflated: the
     * supply is taxable immediately, and the DOCUMENT that states the tax waits. The money the creator
     * earned is not what is uncertain, so it flows; the tax statement is, so it queues. Selling continues
     * throughout — nothing about this creator is in doubt except a registry's turnaround.
     */
    case DomesticStandardRatedPendingValidation = 'de_standard_rated_pending_validation';

    /** Established locally under the small-business exemption: no tax on their own supply. */
    case DomesticSmallBusiness = 'de_small_business';

    /** A small business elsewhere in the union, exempt here by their own registration. */
    case UnionSmallBusinessExempt = 'eu_small_business_de_exempt';

    /** Not in business at all. Their supply carries no tax and they may not be shown any. */
    case PrivateIndividual = 'private_individual';

    /** A business elsewhere in the union, verified against the union's own registry. */
    case UnionBusiness = 'eu_business';

    /** A business outside the union. */
    case NonUnionBusiness = 'non_eu_business';

    /**
     * Nothing has been established. The default, and never a benign one: it is the state a payout hold and
     * a sales hold key off, precisely because guessing would produce a document that is wrong in a way
     * nobody can correct afterwards.
     */
    case Unclarified = 'unclarified';

    /**
     * Whether a document about this creator must WITHHOLD its tax statement pending an external check.
     *
     * Deliberately not the same question as whether the supply is taxable. Conflating the two is what
     * produced the collision this enum's transitional case exists to resolve: a supply can be taxable while
     * the document that states the tax has to wait for somebody else to answer.
     */
    public function withholdsTaxDisclosure(): bool
    {
        return $this === self::DomesticStandardRatedPendingValidation;
    }

    /**
     * Whether this standing stops the creator selling.
     *
     * Only an unestablished standing does. Everything else — including the transitional case — is a known
     * position, and blocking somebody's sales because a registry is slow punishes them for a queue they do
     * not control.
     */
    public function blocksSelling(): bool
    {
        return $this === self::Unclarified;
    }

    /**
     * Whether the creator's own net earnings may be paid out.
     *
     * The net is what the creator earned and it does not depend on how their supply is taxed, so an
     * unresolved tax question must not hold it. Only an unestablished standing does — there, the platform
     * does not yet know who it is paying, which is a different kind of doubt entirely.
     */
    public function permitsNetPayout(): bool
    {
        return $this !== self::Unclarified;
    }

    /**
     * Whether this standing rests on a size relief, and so can be lost by growing.
     *
     * The two that do are the ones a turnover limit applies to. Everything else is either already standard
     * rated, taxed somewhere else, or not established at all — none of which a threshold moves.
     *
     * It lives on the enum rather than in the flip that checks it, because a second place asking the same
     * question is a second place to get it wrong: a new relieved case added to this enum would be flipped by
     * one reader and quietly skipped by the other, and the skip is the direction that keeps issuing tax-free
     * documents to somebody who has outgrown the relief.
     */
    public function reliesOnSizeRelief(): bool
    {
        return $this === self::DomesticSmallBusiness || $this === self::UnionSmallBusinessExempt;
    }
}
