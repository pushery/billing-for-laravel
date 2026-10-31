{{-- The screen in WireKit's components, the one an application with WireKit installed gets. Every look on it comes
     from a component, so a host whose build reads WireKit's views styles it without reading this package's: the view
     sets no class of its own. An application without WireKit gets the plain markup beside it. --}}
<section aria-labelledby="declarations-heading">
    <x-wirekit::stack>
        <x-wirekit::heading :level="2" size="lg" id="declarations-heading">{{ __('billing::admin.declarations.heading') }}</x-wirekit::heading>
        <x-wirekit::text size="sm" intent="muted">{{ __('billing::admin.declarations.intro') }}</x-wirekit::text>

        {{-- Persistent live regions, as in the plain markup: the wrappers stay in the DOM and only their content toggles,
             so a screen reader announces the change. A callout inside them, because a callout is no live region of its
             own, and a region inside a region would be announced twice. --}}
        <div role="status" aria-live="polite">
            @if ($result === 'resolved')
                <x-wirekit::callout intent="success">{{ __('billing::admin.declarations.resolved') }}</x-wirekit::callout>
            @endif
        </div>
        <div role="alert" aria-live="assertive">
            @if ($result === 'gone' || $result === 'invalid_day')
                <x-wirekit::callout intent="danger">{{ __('billing::admin.declarations.'.$result) }}</x-wirekit::callout>
            @endif
        </div>

        @if ($cancellations->isEmpty() && $withdrawals->isEmpty())
            <x-wirekit::text size="sm" intent="muted">{{ __('billing::admin.declarations.empty') }}</x-wirekit::text>
        @endif

        @foreach (['cancellation' => $cancellations, 'withdrawal' => $withdrawals] as $kind => $declarations)
            @foreach ($declarations as $declaration)
                <x-wirekit::card as="article" wire:key="{{ $kind }}-{{ $declaration->id }}" aria-labelledby="{{ $kind }}-{{ $declaration->id }}-heading">
                    <x-wirekit::card.body>
                        <x-wirekit::stack>
                            <x-wirekit::heading :level="3" size="base" id="{{ $kind }}-{{ $declaration->id }}-heading">{{ __('billing::admin.declarations.'.$kind) }}</x-wirekit::heading>

                            {{-- The declaration as the person's confirmation states it, every value as they typed it. --}}
                            <x-wirekit::data-list layout="detail">
                                @foreach ($kind === 'cancellation' ? \Pushery\Billing\Consumer\DeclarationStatement::cancellationLines($declaration) : \Pushery\Billing\Consumer\DeclarationStatement::withdrawalLines($declaration) as $line)
                                    <x-wirekit::data-list.item :label="$line['label']" :value="$line['value']" />
                                @endforeach
                            </x-wirekit::data-list>

                            @if ($declaration->failure !== null)
                                <x-wirekit::text size="sm" intent="warning">{{ __('billing::admin.declarations.failure', ['failure' => \Pushery\Billing\Consumer\DeclarationFailure::sentence($declaration->failure)]) }}</x-wirekit::text>
                            @endif

                            {{-- One row from 48rem up, the field and its button on one line; below that, one above the other. --}}
                            @if ($kind === 'cancellation')
                                <x-wirekit::row as="form" align-fields wire:submit="resolveCancellation({{ $declaration->id }})">
                                    <x-wirekit::input type="date" id="declaration-ends-{{ $declaration->id }}" :label="__('billing::admin.declarations.ends_on')" wire:model="endsOn.{{ $declaration->id }}" />
                                    <x-wirekit::button type="submit" loading-target="resolveCancellation({{ $declaration->id }})">{{ __('billing::admin.declarations.resolve') }}</x-wirekit::button>
                                </x-wirekit::row>
                            @else
                                <form wire:submit="resolveWithdrawal({{ $declaration->id }})">
                                    <x-wirekit::button type="submit" loading-target="resolveWithdrawal({{ $declaration->id }})">{{ __('billing::admin.declarations.resolve') }}</x-wirekit::button>
                                </form>
                            @endif
                        </x-wirekit::stack>
                    </x-wirekit::card.body>
                </x-wirekit::card>
            @endforeach
        @endforeach
    </x-wirekit::stack>
</section>
