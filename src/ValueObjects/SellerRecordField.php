<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SellerFieldBasis;

/**
 * One field a reporting profile asks a seller for, and why.
 *
 * The name is neutral. A field called after one country's statute would travel into every consumer's
 * database and every consumer's privacy notice, including consumers that country's law has nothing to do
 * with — and renaming it later means migrating live data.
 */
final readonly class SellerRecordField
{
    public function __construct(
        /** The neutral name this field is stored and validated under. */
        public string $name,
        public SellerFieldBasis $basis,
        /**
         * Whether the field is sensitive enough that it must never leave the record.
         *
         * Not every field is: a business address appears on documents by design. An identifier issued to a
         * person, and their date of birth, appear nowhere — not in a log line, not in an export, not in an
         * error message. Marking it here is what lets a guard check it rather than a convention.
         */
        public bool $sensitive = false,
    ) {}
}
