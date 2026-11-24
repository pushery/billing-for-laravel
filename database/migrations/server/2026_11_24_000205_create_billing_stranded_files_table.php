<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stored document file the disk did not remove when `billing:prune` pruned the record that named it. The record is
 * gone, so this row is what lets the next run try again. `fingerprint` is the SHA-256 of the disk and the path, which a
 * unique index holds where the path itself is too long for one on MySQL. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_stranded_files', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64);
            $table->string('disk');
            $table->string('path', 1024);
            // With its zone on PostgreSQL and as a DATETIME on MySQL, like every moment of the package's tables.
            $table->dateTimeTz('failed_at');

            $table->unique('fingerprint', 'billing_stranded_files_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_stranded_files');
    }
};
