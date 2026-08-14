<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Marketplace\MerchantLifecycle;
use Pushery\Billing\Models\MerchantAccount;

/**
 * Begin again with a merchant whose relationship with the platform had ended.
 *
 * ## The state this exists for
 *
 * A merchant disconnects their provider account — by accident, while tidying up app connections, or because
 * a collaboration ended and is now resuming. They are terminated here and cannot receive money, and until
 * this command there was no way back: the column recording the disconnection was set in one place and
 * cleared nowhere, the only writer of the active status refused a terminated merchant by design, and
 * onboarding handed the old unreceivable row straight back with exit 0.
 *
 * So the documented repair — "onboard them again" — produced a plausible-looking success and changed
 * nothing. The remaining option was an UPDATE against the table by hand, past every invariant the package
 * holds, with no event and no audit trail.
 *
 * ## Why a command and not a webhook
 *
 * `MerchantLifecycle` refuses to let a capability report reinstate a terminated merchant, because a provider
 * goes on reporting healthy capabilities for an account long after its owner disconnected it. That guard
 * only means something if the way back is somewhere a webhook cannot reach. Reopening decides that a
 * relationship somebody ended should begin again — a person's decision, with a person's name on the run.
 */
final class MerchantReopenCommand extends Command
{
    protected $signature = 'billing:merchant:reopen
        {type : the merchant\'s morph alias or class name}
        {id : its key}';

    protected $description = 'Begin again with a merchant whose relationship with the platform had ended';

    public function handle(MerchantLifecycle $lifecycle): int
    {
        $merchant = $this->resolveMerchant();

        if (! $merchant instanceof Model) {
            return self::FAILURE;
        }

        $account = MerchantAccount::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->first();

        if (! $account instanceof MerchantAccount) {
            $this->components->error('This merchant has no connected account to reopen. Onboard them with billing:merchant:onboard.');

            return self::FAILURE;
        }

        // A named no-op rather than a silent one: a merchant who was never terminated has live capability
        // flags, and reopening would clear them for no reason. Saying so is the difference between "nothing
        // to do" and "it did not work".
        if (! $lifecycle->reopen($account)) {
            $this->components->info('This merchant is not terminated, so there is nothing to reopen. Their capabilities are untouched.');

            return self::SUCCESS;
        }

        $this->components->info('The relationship is open again. The merchant is active and the disconnection is cleared.');

        // Said out loud because it is the question the operator will ask next. The three capability flags are
        // deliberately back to false: they were gathered before the disconnection, and the provider has not
        // spoken since. It raises them itself once it has.
        $this->components->warn(
            'They cannot receive money yet. The capability flags are reset on purpose — the provider gathered '
            .'the old ones before the disconnection — so let it report again, or run billing:merchant:refresh, '
            .'and check with billing:merchant:status.'
        );

        return self::SUCCESS;
    }

    /** The merchant behind the two arguments, or null with an error printed. */
    private function resolveMerchant(): ?Model
    {
        $type = (string) $this->argument('type');
        $id = $this->argument('id');

        // The morph ALIAS as readily as the class, for the reason the onboarding command gives: an
        // installation with a morph map has no reason to know its own class names at the command line.
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->components->error("No model is registered for '{$type}'. Pass the morph alias or the class name.");

            return null;
        }

        $merchant = $class::query()->find($id);

        return $merchant instanceof Model ? $merchant : $this->missing($type, $id);
    }

    private function missing(string $type, mixed $id): null
    {
        $this->components->error("No {$type} exists with key ".(is_scalar($id) ? (string) $id : '(unreadable)').'.');

        return null;
    }
}
