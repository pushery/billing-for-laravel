{{--
    A minimal, UI-kit-free home for the toasts the realtime bridge dispatches.

    Off by default and mounted only behind `billing.realtime.render_toast_region`, because the consumer for
    whom toasts already work is a WireKit host: WireKit renders its own region reading exactly this event, and
    a second one would show every toast twice. That duplicate is visible only in a browser, which makes it a
    bad thing to introduce while fixing a silence.

    TWO regions rather than one with a switched attribute. `aria-live` is honored when the region is
    announced, and changing it on a live region is unreliable across assistive technology — so the urgency is
    decided by WHICH container a toast is appended to. `polite` waits for a pause, which is right for "your
    subscription is active"; `assertive` interrupts, which is right for "your payment could not be
    processed". The mapping is the one `ToastLevel` already carries.

    The listener is inline on purpose: this package ships no JavaScript build, and the account policy already
    allows `'unsafe-inline'` for Livewire and Alpine. A separate asset would mean a build step for a consumer
    who only wanted the hub to work.
--}}
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4">
    <div id="billing-toasts-polite" aria-live="polite" aria-atomic="false" class="contents"></div>
    <div id="billing-toasts-assertive" aria-live="assertive" aria-atomic="false" class="contents"></div>
</div>

<script>
    (function () {
        var STYLES = {
            'info': 'bg-slate-800 text-white',
            'success': 'bg-emerald-700 text-white',
            'warning': 'bg-amber-600 text-white',
            'danger': 'bg-red-700 text-white',
        };

        // Which region each severity is announced through. Read as data rather than branched on, so a level
        // this file does not know about lands in the polite region with the neutral style instead of
        // throwing away the message.
        var URGENCY = { 'info': 'polite', 'success': 'polite', 'warning': 'assertive', 'danger': 'assertive' };

        window.addEventListener('wirekit-toast', function (event) {
            var detail = (event && event.detail) || {};
            var message = typeof detail.message === 'string' ? detail.message : '';

            if (message === '') {
                return;
            }

            var variant = Object.prototype.hasOwnProperty.call(URGENCY, detail.variant) ? detail.variant : 'info';
            var region = document.getElementById('billing-toasts-' + URGENCY[variant]);

            if (!region) {
                return;
            }

            var toast = document.createElement('div');
            toast.className = 'pointer-events-auto max-w-md rounded-lg px-4 py-3 text-sm shadow-lg ' + STYLES[variant];
            // textContent, never innerHTML: a broadcast payload is untrusted, and the bridge clamps only the
            // SEVERITY. The message is whatever the application sent, so it is written as text.
            toast.textContent = message;

            var dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'ml-3 underline';
            dismiss.textContent = @json(__('billing::account.toast.dismiss'));
            dismiss.addEventListener('click', function () {
                toast.remove();
            });
            toast.appendChild(dismiss);

            region.appendChild(toast);

            window.setTimeout(function () {
                toast.remove();
            }, 8000);
        });
    })();
</script>
