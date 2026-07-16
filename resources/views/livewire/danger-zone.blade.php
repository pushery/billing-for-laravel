<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.danger.heading') }}</h1>
    </header>

    <section class="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/30">
        <p class="text-sm text-red-800 dark:text-red-200">{{ __('billing::account.danger.explanation') }}</p>

        @unless ($confirming)
            <button type="button" wire:click="confirm"
                class="mt-4 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500">
                {{ __('billing::account.danger.cancel_now') }}
            </button>
        @else
            <p class="mt-4 text-sm font-semibold text-red-900 dark:text-red-100">{{ __('billing::account.danger.confirm_question') }}</p>

            {{-- Re-confirm identity before the irreversible cancel. A wrong secret / throttle lockout blocks it. --}}
            <div class="mt-3">
                <label for="reconfirm" class="block text-sm font-medium text-red-900 dark:text-red-100">
                    {{ __('billing::account.reconfirm.prompt') }}
                </label>
                <input id="reconfirm" type="password" wire:model="credential" autocomplete="off"
                    class="mt-1 w-full max-w-sm rounded-lg border border-red-300 px-3 py-2 text-sm dark:border-red-800 dark:bg-gray-900"
                    aria-describedby="reconfirm-error">
                @error('credential')
                    <p id="reconfirm-error" role="alert" class="mt-1 text-sm font-medium text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-3 flex gap-3">
                <button type="button" wire:click="cancelNow" wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50">
                    {{ __('billing::account.danger.confirm_yes') }}
                </button>
                <button type="button" wire:click="abort"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('billing::account.danger.confirm_no') }}
                </button>
            </div>
        @endunless
    </section>
</div>
