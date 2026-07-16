<?php

declare(strict_types=1);

namespace Pushery\Billing\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Override;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Support\BillingBanner;
use Pushery\Billing\ValueObjects\BannerNotice;

/**
 * The app-shell billing banner. Drop `<x-billing::banner />` into your layout: it resolves the signed-in
 * actor's billing owner itself and renders the one notice that needs attention (a failed payment, a
 * lapsing grace period, a trial about to end), or nothing at all when the account is healthy — so it is
 * safe to leave in the shell permanently.
 */
final class Banner extends Component
{
    public ?BannerNotice $notice;

    public function __construct(BillingBanner $banner, BillingEntityResolver $resolver)
    {
        $actor = Auth::user();

        $this->notice = $actor instanceof Model ? $banner->for($resolver->ownerFor($actor)) : null;
    }

    #[Override]
    public function shouldRender(): bool
    {
        return $this->notice instanceof BannerNotice;
    }

    public function render(): View
    {
        return view('billing::components.banner');
    }
}
