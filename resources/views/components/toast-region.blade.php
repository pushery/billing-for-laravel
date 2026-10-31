{{--
    A minimal, UI-kit-free home for the toasts the realtime bridge dispatches.

    Off by default and mounted only behind `billing.realtime.render_toast_region`, because the consumer for
    whom toasts already work is a WireKit host: WireKit renders its own region reading exactly this event, and
    a second one would show every toast twice. That duplicate is visible only in a browser, which makes it a
    bad thing to introduce while fixing a silence.

    Two regions rather than one with a switched attribute. `aria-live` is honored when the region is
    announced, and changing it on a live region is unreliable across assistive technology — so the urgency is
    decided by which container a toast is appended to. `polite` waits for a pause, which is right for "your
    subscription is active"; `assertive` interrupts, which is right for "your payment could not be
    processed". The mapping is the one `ToastLevel` already carries.

    The listener is a file of the application's own origin, `AccountScript::ToastRegion`, so a host whose policy
    says `script-src 'self'` without `'unsafe-inline'` runs it; the hub's own policy allows it through `'self'` as
    well. A policy built on `'strict-dynamic'` ignores `'self'`, and there the host allows the file itself. The
    label of the dismiss button travels on the container, because the file is the same in every locale.
--}}
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4" data-billing-toast-dismiss="{{ __('billing::account.toast.dismiss') }}">
    <div id="billing-toasts-polite" aria-live="polite" aria-atomic="false" class="contents"></div>
    <div id="billing-toasts-assertive" aria-live="assertive" aria-atomic="false" class="contents"></div>
</div>

<script src="{{ \Pushery\Billing\Enums\AccountScript::ToastRegion->url() }}" defer></script>
