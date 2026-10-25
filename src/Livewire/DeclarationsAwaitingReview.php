<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFacade;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\Billing\Consumer\CancellationButton;
use Pushery\Billing\Consumer\DeclarationStatement;
use Pushery\Billing\Consumer\WithdrawalFunction;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The declarations through the cancellation button and the withdrawal function that wait for a person, and the act
 * that deals with one.
 *
 * Part of the admin console, and a component of its own, so that an application without the console mounts it in a
 * back office of its own, under the name the guide gives. Authorized like the console, against `billing.admin.ability`
 * and fail-closed, on every entry point. Resolving a cancellation records the end it came to: a day the person enters,
 * which the contract ends with at the close of that day in the zone the declarations are stated in, or none, which
 * keeps the end on the row.
 */
final class DeclarationsAwaitingReview extends Component
{
    /**
     * The day each cancellation ends on, keyed by its id, as `Y-m-d` — client input; resolveCancellation() re-authorizes
     * and reads it as a day of the calendar or refuses it.
     *
     * @var array<int|string, string>
     */
    public array $endsOn = [];

    /** What the last act came to, for the live regions: `resolved`, `gone` or `invalid_day`. */
    #[Locked]
    public ?string $result = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function render(): View
    {
        // On every render as well as on mount: a re-render must not show declarations to somebody whose grant was
        // withdrawn between two requests.
        $this->authorizeAdmin();

        return ViewFacade::make('billing::livewire.declarations-awaiting-review', [
            'cancellations' => CancellationDeclaration::model()::query()->awaitingReview()->get(),
            'withdrawals' => WithdrawalDeclaration::model()::query()->awaitingReview()->get(),
        ]);
    }

    /** Record that a person dealt with a cancellation, with the end it came to where they entered one. */
    public function resolveCancellation(int $declaration): void
    {
        $this->authorizeAdmin();

        $found = CancellationDeclaration::model()::query()->awaitingReview()->find($declaration);

        if (! $found instanceof CancellationDeclaration) {
            $this->result = 'gone';

            return;
        }

        $day = trim($this->endsOn[$declaration] ?? '');
        $endsAt = null;

        if ($day !== '') {
            // A day of the calendar and nothing else: the parser beneath also reads "tomorrow" and "+1 year", which a
            // date field never sends and a crafted request could.
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $this->result = 'invalid_day';

                return;
            }

            $endsAt = CarbonImmutable::parse($day, DeclarationStatement::zone())->endOfDay()->utc();
        }

        Container::getInstance()->make(CancellationButton::class)->resolve($found, $endsAt, $this->actor());

        unset($this->endsOn[$declaration]);
        $this->result = 'resolved';
    }

    /** Record that a person dealt with a withdrawal. */
    public function resolveWithdrawal(int $declaration): void
    {
        $this->authorizeAdmin();

        $found = WithdrawalDeclaration::model()::query()->awaitingReview()->find($declaration);

        if (! $found instanceof WithdrawalDeclaration) {
            $this->result = 'gone';

            return;
        }

        Container::getInstance()->make(WithdrawalFunction::class)->resolve($found, $this->actor());

        $this->result = 'resolved';
    }

    private function actor(): ?Model
    {
        $user = Auth::user();

        return $user instanceof Model ? $user : null;
    }

    private function authorizeAdmin(): void
    {
        $ability = Config::get('billing.admin.ability', 'billing-admin');

        if (! Gate::allows(is_string($ability) ? $ability : 'billing-admin')) {
            throw new HttpException(403);
        }
    }
}
