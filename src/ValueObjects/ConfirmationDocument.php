<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One document attached to the confirmation of a contract, as the application hands it over.
 */
final readonly class ConfirmationDocument
{
    public function __construct(
        /** The file name the buyer sees, with the version of the document in it where it has one. */
        public string $filename,
        /** The document itself, as the bytes of the file. */
        public string $contents,
        /** Its MIME type, `text/html` or `application/pdf` for instance. */
        public string $mime,
    ) {}
}
