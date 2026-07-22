<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Exceptions\PostureNotPermitted;

/**
 * Resolves the seller-of-record posture for a single routed sale — the one place the "who is the seller to
 * the buyer" decision is made. The default binding reads it from config (a global default plus a per-merchant
 * or per-product-class override, all inside the opted-in whitelist); a consumer with a richer rule binds its
 * own. The resolved posture is meant to be frozen onto the sale, so a later config change never rewrites a
 * receipt that was already issued.
 *
 * `$suppliesAreElectronic` drives the legal test: an electronic service falls under the Art. 9a deemed-
 * supplier presumption, physical goods do not — so the same platform can be the deemed supplier of a
 * download and a mere intermediary of a shipped item.
 */
interface SellerOfRecordResolver
{
    /**
     * @param  bool  $suppliesAreElectronic  whether this sale is an electronically-supplied service
     * @param  string|null  $override  an opted-in posture value (per merchant or product class), or null for the default
     *
     * @throws PostureNotPermitted when the resolved posture is outside the
     *                             allowed whitelist, or is `SellerOfRecord` for an electronic supply without a genuine Art. 9a rebuttal
     */
    public function resolveFor(bool $suppliesAreElectronic, ?string $override = null): SellerOfRecordPosture;
}
