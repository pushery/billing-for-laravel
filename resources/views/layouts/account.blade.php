@inject('accountNav', \Pushery\Billing\Account\Navigation::class)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The document title is the active nav item's label (typed, from the registry), falling back to a
         page-set title and finally the hub name — never an untyped Livewire title macro. --}}
    <title>{{ $accountNav->activeTitle() ?? ($title ?? __('billing::account.title')) }}</title>
    @if (config('account.stylesheet'))
        <link rel="stylesheet" href="{{ config('account.stylesheet') }}">
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    {{-- Skip link: a keyboard or screen-reader user jumps straight past the nav to the content. --}}
    <a href="#main-content"
        class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-medium shadow focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 dark:bg-gray-900">
        {{ __('billing::account.skip_to_content') }}
    </a>

    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:flex-row">
        {{-- Header + grouped sidebar nav. This shell is plain, framework-agnostic markup so the package needs
             no UI-kit dependency; publish `billing-views` to replace it wholesale (e.g. with your own design
             system's app shell).

             Below `sm` the nav wraps its links into a few rows, without its group labels. Stacked above the
             content it was a column of eight links, and on a phone every screen's own heading began below the
             first screen. Wrapping rather than scrolling sideways keeps every destination on screen: a row that
             scrolls hides whatever sits past its edge and gives no sign that anything does. --}}
        <header class="sm:w-56 sm:shrink-0">
            <div class="flex items-center justify-between">
                <a href="{{ route('billing.account.overview') }}" wire:navigate class="text-lg font-semibold">
                    {{ __('billing::account.title') }}
                </a>
                {{-- Logout is the consuming app's route; only render it (as a POST form, never a GET link) when
                     that app actually registers one. --}}
                @if (\Illuminate\Support\Facades\Route::has('logout'))
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                            class="text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100">
                            {{ __('billing::account.logout') }}
                        </button>
                    </form>
                @endif
            </div>

            <nav class="mt-4 flex flex-wrap gap-x-3 gap-y-1 sm:mt-6 sm:block sm:space-y-6" aria-label="{{ __('billing::account.title') }}">
                @foreach ($accountNav->visible() as $group)
                    <div wire:key="nav-group-{{ $group['key'] }}">
                        {{-- gray-500 in the light scheme: gray-400 on the gray-50 page measured 2.48:1, under the
                             4.5:1 small text needs. The dark scheme keeps gray-400, which reads well on gray-950. --}}
                        <p class="hidden px-2 text-xs font-semibold uppercase tracking-wide text-gray-500 sm:block dark:text-gray-400">
                            {{ __($group['label']) }}
                        </p>
                        <ul class="flex flex-wrap gap-1 sm:mt-2 sm:block sm:space-y-1">
                            @foreach ($group['items'] as $item)
                                <li wire:key="nav-item-{{ $item['key'] }}">
                                    <a href="{{ $item['url'] }}" wire:navigate
                                        @if ($item['active']) aria-current="page" @endif
                                        @class([
                                            'block whitespace-nowrap rounded-lg px-2 py-1.5 text-sm',
                                            'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $item['active'],
                                            'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800' => ! $item['active'],
                                        ])>
                                        {{ __($item['label']) }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </header>

        <main id="main-content" class="min-w-0 flex-1">
            {{ $slot }}
        </main>
    </div>

    {{-- The headless realtime bridge (broadcast toasts) mounts only when broadcasting is on and the runtime is
         not native — off by default, so nothing is required to render the hub. --}}
    @if (config('billing.realtime.enabled') && config('billing.runtime') !== 'native')
        <livewire:billing.account-realtime />

        {{-- And somewhere for those toasts to land, for a consumer whose host renders no region of its own.
             Off by default: a WireKit host already reads this event, and a second region would show every
             toast twice. --}}
        @if (config('billing.realtime.render_toast_region'))
            @include('billing::components.toast-region')
        @endif
    @endif

    @livewireScripts
</body>
</html>
