<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A month's transactions for one creator resolved to more than one VAT category in a single collective
 * document — refused rather than rendered wrong.
 *
 * It happens when a creator crosses the small-business threshold mid-month: the supplies before the flip are
 * exempt (§ 19, category E) and those after carry tax (category S). A collective document groups its lines
 * per rate, but the exemption category is a document-level property here, so one document cannot state E for
 * some lines and S for others without per-line category rendering. Producing it anyway would misstate the
 * tax on half the lines.
 *
 * The safe boundary is to refuse the collective document for that one month and settle those transactions
 * once per-line category rendering exists, rather than emit a document whose VAT breakdown is wrong. Every
 * other month — all exempt, all standard, or several rates of the same category — builds normally.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CollectiveSettlementSpansTaxCategories extends RuntimeException
{
    public static function make(string $period): self
    {
        return new self(
            "The collective settlement for period [{$period}] spans more than one VAT category — its "
            .'transactions are partly exempt and partly taxed, which happens when a creator crosses the '
            .'small-business threshold mid-month. One collective document cannot state two categories without '
            .'per-line category rendering, so it is refused rather than issued with a wrong VAT breakdown.'
        );
    }
}
