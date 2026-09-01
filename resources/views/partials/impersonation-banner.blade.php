@php
    $impersonation = session(\App\Services\ImpersonationService::SESSION_KEY);
@endphp

@if (is_array($impersonation) && !empty($impersonation['session_id']))
    <div class="impersonation-banner d-flex flex-wrap align-items-center justify-content-center gap-2">
        <span class="impersonation-badge">IMPERSONATED</span>
        <span>
            Viewing <strong>{{ $impersonation['organization_name'] }}</strong>
            as <strong>{{ auth()->user()?->name }}</strong>
            &mdash; session ends
            <strong id="impersonation-countdown"
                    data-expires-at="{{ $impersonation['expires_at'] }}">shortly</strong>
        </span>
        <form method="POST" action="{{ route('impersonation.stop') }}" class="m-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-light fw-semibold py-0 px-2">Stop impersonating</button>
        </form>
    </div>

    <style>
        .impersonation-banner {
            position: sticky;
            top: 0;
            z-index: 1100;
            background: repeating-linear-gradient(45deg, #b02a37, #b02a37 12px, #92232e 12px, #92232e 24px);
            color: #fff;
            font-size: .82rem;
            padding: .4rem .75rem;
        }

        .impersonation-badge {
            background: #fff;
            color: #b02a37;
            font-weight: 700;
            letter-spacing: .06em;
            border-radius: .2rem;
            padding: 0 .4rem;
        }
    </style>

    <script>
        (function () {
            const el = document.getElementById('impersonation-countdown');
            if (!el) return;
            const expiresAt = new Date(el.dataset.expiresAt).getTime();

            const tick = () => {
                const remaining = Math.max(0, expiresAt - Date.now());
                const minutes = Math.floor(remaining / 60000);
                const seconds = Math.floor((remaining % 60000) / 1000);
                el.textContent = `in ${minutes}:${String(seconds).padStart(2, '0')}`;
                if (remaining === 0) {
                    window.location.reload();
                }
            };

            tick();
            setInterval(tick, 1000);
        })();
    </script>
@endif
