<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free sequential number sources (per scope, e.g. per year) for legally-immutable invoice
 * numbers. Each scope holds the next value; it is handed out under a row lock so numbering has no
 * gaps and no duplicates even under concurrency. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_number_sequences');
    }
};
