<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CountryEvidencePolicy;
use Pushery\Billing\ValueObjects\CountryEvidence;
use Pushery\Billing\ValueObjects\CountrySignals;

/**
 * The shipped reading: the payment instrument leads, and the buyer can outvote it with corroboration.
 *
 * Three rules, in order:
 *
 * 1. Where the money comes from is the strongest single signal, because it is the hardest for a buyer to
 *    choose casually — a card is issued somewhere and stays issued there.
 * 2. A buyer who says otherwise AND whose connection agrees with them outranks it. Two sources against one
 *    is the ordinary shape of a genuine traveller or an expatriate, and refusing them would turn a correct
 *    self-declaration into a failed sale.
 * 3. Anything else is asked, never guessed. A contradiction the sources cannot settle is settled by the one
 *    party who actually knows, and picking a side quietly would attribute a sale to a country on the
 *    strength of nothing.
 *
 * How many sources must speak at all is configurable, because the legal answer depends on turnover: one
 * piece of evidence below a threshold, two non-contradicting ones above it. The package ships the stricter
 * setting, since the direction of a wrong choice matters — too much evidence costs a checkout question,
 * too little costs a defensible position.
 */
final readonly class PaymentCountryLeadsPolicy implements CountryEvidencePolicy
{
    public function __construct(private Repository $config) {}

    public function version(): string
    {
        return 'payment-leads/1';
    }

    public function resolve(CountrySignals $signals): CountryEvidence
    {
        $declared = $signals->normalized($signals->declared);
        $payment = $signals->normalized($signals->payment);
        $ip = $signals->normalized($signals->ip);

        // Not enough sources spoke to support a position at all. Distinct from a contradiction: asking the
        // buyer cannot manufacture a second independent source, so this is a refusal rather than a question.
        if ($signals->count() < $this->requiredSignals()) {
            return new CountryEvidence(null, $signals, $this->version());
        }

        if ($signals->agree()) {
            return $this->settled($signals->distinct()[0], $signals);
        }

        // The buyer outvotes the instrument when their connection corroborates them.
        if ($declared !== null && $declared === $ip && $declared !== $payment) {
            return $this->settled($declared, $signals);
        }

        // The instrument leads where nothing outranks it — including against a lone declaration, which is
        // the case a buyer could otherwise use to choose their own tax rate.
        if ($payment !== null && ($ip === null || $ip === $payment || $declared === null)) {
            return $this->settled($payment, $signals);
        }

        // Two sources disagreeing with no third to break the tie. The buyer knows; nobody else does.
        return new CountryEvidence($payment ?? $declared ?? $ip, $signals, $this->version(), needsBuyerConfirmation: true);
    }

    /**
     * A settled answer, carrying whatever subdivision the SAME sources support.
     *
     * The subdivision is read from the sources that named THIS country — never from whichever source
     * happened to know a state — and only where the country has subdivisions in scope. Everything else
     * resolves to null, which the counter downstream reads as an explicit `unknown`.
     *
     * The unresolved and needs-confirmation paths deliberately do not come through here. A sale that cannot
     * say which country it happened in has no business recording which state, and evidence that is asking
     * the buyer a question has not settled anything yet.
     */
    private function settled(string $country, CountrySignals $signals): CountryEvidence
    {
        return new CountryEvidence(
            $country,
            $signals,
            $this->version(),
            subdivision: new ResolvedSubdivision($this->config)->resolve($country, $signals),
        );
    }

    /**
     * How many sources must name a country before a sale can rest on them.
     *
     * Refused rather than defaulted when unreadable: a broken value silently becoming 1 would loosen the
     * evidence standard, which is the direction that costs a defensible position rather than a click.
     */
    private function requiredSignals(): int
    {
        return new RequiredCountrySignals($this->config)->count();
    }
}
