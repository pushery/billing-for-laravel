<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A jurisdiction profile that knows the wording a margin-taxed document must carry.
 *
 * The wording is not decoration and not a translation. It is the statement that makes the document legible
 * as a margin-taxed one — without it the document looks like an ordinary sale with no tax stated, which is a
 * different thing entirely and reads to a buyer as an oversight they may try to correct by asking for a tax
 * figure that must never be given.
 *
 * It lives on the profile because the exact words are prescribed per jurisdiction and prescribed exactly.
 * Paraphrasing them is not a smaller version of complying.
 */
interface SuppliesMarginSchemeWording
{
    /**
     * The translation key of the words such a document must carry.
     *
     * A key rather than the words, because each language has its own prescribed form and the document is
     * read in the language it was issued in. What is never permitted is composing or paraphrasing them.
     */
    public function marginSchemeNote(): string;
}
