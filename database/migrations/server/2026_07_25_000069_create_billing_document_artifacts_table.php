<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The electronic document exactly as it was issued.
 *
 * Re-rendering the XML from the database years later is not the same thing and does not satisfy what is
 * being kept. The stored row moves — a rate table is corrected, a party's address is updated, a serializer
 * is improved — and every one of those changes silently produces a "copy" that differs from what the
 * recipient actually holds. The only version worth keeping is the one that left.
 *
 * Kept on the owner's own terms: the artifact carries the same personal data the invoice does and is
 * unlinked on erasure exactly as the invoice is, never deleted separately. A document whose XML was purged
 * while its row survived would be a financial record that can no longer prove what it stated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_document_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner', 'billing_document_artifacts_owner_index');
            $table->string('document_number', 64);
            $table->string('syntax', 16);
            $table->timestamp('issued_at');
            $table->string('checksum', 64);
            $table->text('contents');
            $table->timestamp('owner_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['document_number', 'syntax'], 'billing_document_artifacts_document_syntax_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_artifacts');
    }
};
