<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IpCountryResolver;

/**
 * A webhook delivery reached a billing endpoint and its verifier refused it.
 *
 * ## Why a refusal needs an event at all
 *
 * The refusal itself is correct and has never been in doubt. What was missing is that it existed ONLY as
 * an HTTP status handed back to whoever sent it: no event, no log line, no delivery row. A consumer
 * looking for the moment somebody probed their payment endpoint found nothing, because nothing was ever
 * written — and the failure direction is the quiet one, since the endpoint behaves correctly the whole
 * time.
 *
 * A refused delivery is a security event. It is a misconfiguration, an expired signature, or somebody
 * trying; all three are worth seeing, and the third is worth seeing most.
 *
 * ## What it deliberately does NOT carry
 *
 * **Not the body.** By definition it did not verify, so it is attacker-controlled input; putting it on an
 * event invites it into logs, queues and audit tables that were sized for trusted data.
 *
 * **Not which part of the signature failed.** That is a probing oracle: an attacker who learns whether
 * the header shape, the timestamp or the digest was wrong learns how to get closer.
 *
 * **Not the caller's network address**, and this one is a promise the package makes structurally rather
 * than a judgement call made here. An address reaches exactly one place in this package — the argument of
 * the {@see IpCountryResolver} a consumer bound — and nothing else may read one, because a second path is
 * where it leaks. An event is the worst of those paths: it travels into queued payloads, audit tables and
 * exception context, which is precisely the journey the guarantee exists to prevent.
 * `PlaceEvidencePrivacyTest` fails the build over it, and it did over this event.
 *
 * A consumer who wants the address is not blocked by that and should not be: their listener runs inside
 * the request, so the address is theirs to take from it. Then keeping it is their decision, in their
 * privacy notice, on their storage — which is where a decision about personal data belongs.
 *
 * (Written without naming the accessor, because the guard matches on the literal call and a comment
 * explaining the rule would otherwise break it. That is not a quirk worth working around silently: a
 * text scan cannot tell prose from code, and the cheap fix is to describe rather than quote.)
 *
 * **Not a reason field.** The `WebhookVerifier` contract answers yes or no — consumers implement it, so
 * it cannot grow a reason without breaking their code, and a field that could only ever hold one value
 * would describe nothing while looking like it described something.
 *
 * ## Never a domain event
 *
 * It deliberately does not implement `BillingDomainEvent`. Those are provider facts routed through the
 * effect registry, and this is not a fact about money — it is a fact about a request. Routing it through
 * the registry would hand attacker-triggered traffic to effects built for verified deliveries.
 */
final readonly class WebhookDeliveryRefused
{
    public function __construct(
        /** The driver the endpoint is running, so a multi-driver install can tell the two apart. */
        public string $provider,
        /** `platform` or `marketplace` — the two endpoints answer for different money and different secrets. */
        public string $surface,
        public string $path,
        public ?string $userAgent,
    ) {}
}
