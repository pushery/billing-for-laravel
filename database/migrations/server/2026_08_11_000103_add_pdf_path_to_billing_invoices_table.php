<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the issued PDF of an invoice lives, when a consumer kept one.
 *
 * The package renders a PDF on demand and stores none — deliberately, because storage is a consumer
 * decision with a disk, a retention policy and a bill behind it. But a re-render is not the document that
 * was issued: everything under a renderer moves over the years an invoice must remain readable, and each
 * change silently yields a "copy" the recipient's version disagrees with. That is the same reasoning
 * `billing_document_artifacts` already applies to the XML forms; this is the anchor for the human-readable
 * one, which the package cannot keep for the consumer.
 *
 * It is a POINTER and nothing more. The package never writes it, never reads bytes through it, and takes no
 * view on what it addresses — a disk-relative path, a media-library id, an object key. A consumer that
 * attaches the PDF (Lane does, via its media library) records where, so a later download can serve what was
 * issued rather than what renders today.
 *
 * Nullable with no default and no backfill: an absent value means nobody kept one, which is the honest
 * state for every row that exists before this migration and for every install that never stores PDFs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('pdf_path');
        });
    }
};
