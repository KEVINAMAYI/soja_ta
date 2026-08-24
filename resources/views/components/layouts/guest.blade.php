<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: #0a2540;
        }

        .guest-page {
            display: grid;
            min-height: 100vh;
            padding: 1.5rem;
            place-items: center;
            background: rgba(10, 37, 64, 0.76);
        }

        .guest-modal {
            width: min(100%, 34rem);
            padding: clamp(1.5rem, 4vw, 2.5rem);
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.32);
        }
    </style>
</head>
<body>
<main class="guest-page">
    <section class="guest-modal" role="dialog" aria-modal="true">
        {{ $slot }}
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

@fluxScripts
</body>
</html>