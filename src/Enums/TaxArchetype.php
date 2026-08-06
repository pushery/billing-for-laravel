<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What kind of thing was sold, as far as tax is concerned.
 *
 * This is the classification everything else derives from — where it is taxed, which rate band applies,
 * whether the sale is reportable. It is frozen onto the transaction rather than read back from the product,
 * because a product can be reclassified after it has been sold: an author adds a video to a text-only work,
 * a creator turns a broadcast into a private session. Both are legitimate changes to the product and
 * neither may reach back into a sale that already happened.
 *
 * The failure that makes this worth freezing has no visible symptom. Nothing about the old document looks
 * wrong; only the relationship between it and the product has quietly stopped holding, so a refund months
 * later reverses an amount that was never declared. The cases are neutral: which rule and which band each
 * one implies is a jurisdiction's answer, not the core's.
 */
enum TaxArchetype: string
{
    /** A pre-produced file the buyer downloads. */
    case Download = 'download';

    /** Recurring access to a library or a community. */
    case Subscription = 'subscription';

    /** A text-and-image work — the case a reduced band most often exists for. */
    case Ebook = 'ebook';

    /**
     * A bundle containing audio or video. Its own case rather than a flag on the others, because any such
     * content disqualifies the reduced band outright — a distinction that disappears if it is left to be
     * re-derived from a product whose contents can change.
     */
    case BundleWithAudioVideo = 'bundle_with_av';

    /** A broadcast to an audience, rather than to one buyer. */
    case Livestream = 'livestream';

    /** Work commissioned by one buyer, for them alone. */
    case CustomOneToOne = 'custom_one_to_one';

    /** A voluntary payment on top of another sale, which it follows in every respect. */
    case Tip = 'tip';

    /** A multi-purpose voucher: nothing is taxed until it is redeemed for something else. */
    case Voucher = 'voucher';

    /** Second-hand goods sold between private people, where the platform only intermediates. */
    case ConsumerGoods = 'consumer_goods';

}
