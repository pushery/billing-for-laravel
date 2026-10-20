<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Webhooks\WebhookPayloadSecrets;

/**
 * Removes the `client_secret` from the delivery payloads already stored.
 *
 * The ledger strips the field before it stores a delivery from now on. A payload stored before that keeps it until the
 * retention clock prunes it, 90 days by default, and Stripe asks for the field not to be stored at all. A payload that
 * carries no such field is read and left as it is; nothing else on a row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_webhook_events')
            ->whereNotNull('payload')
            ->select(['id', 'payload'])
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $stripped = WebhookPayloadSecrets::stripJson($row->payload);

                    if ($stripped !== $row->payload) {
                        DB::table('billing_webhook_events')->where('id', $row->id)->update(['payload' => $stripped]);
                    }
                }
            });
    }

    /** Deliberately nothing: a removed secret is kept nowhere it could be put back from. */
    public function down(): void {}
};
