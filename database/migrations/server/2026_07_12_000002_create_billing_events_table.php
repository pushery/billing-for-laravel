<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing-domain audit ledger. Every meaningful billing event (plan sync, credit, dunning
 * notice, admin action) is appended here with an optional polymorphic subject and a JSON payload,
 * for the admin audit viewer and after-the-fact debugging. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->nullableMorphs('subject');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
