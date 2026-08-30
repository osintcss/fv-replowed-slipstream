<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $minimal ? 'Sign in with Discord' : 'FV Classic' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body {
                margin: 0;
                font-family: 'Figtree', sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #1a472a 0%, #2d5016 30%, #4a7c23 60%, #2d5016 100%);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }
            .guest-brand {
                font-size: 1.5rem;
                font-weight: 700;
                color: #fbbf24;
                text-decoration: none;
                margin-bottom: 1.5rem;
                display: block;
                text-align: center;
            }
            .guest-card {
                width: 100%;
                max-width: 28rem;
                margin: 0 auto;
                padding: 2rem;
                background: rgba(255, 255, 255, 0.95);
                border-radius: 1rem;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            }
            @media (prefers-color-scheme: dark) {
                .guest-card {
                    background: rgba(31, 41, 55, 0.95);
                }
            }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div style="padding: 2rem; width: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh;">
            @if (!$minimal)
                <a href="/" class="guest-brand">FV Classic</a>
            @endif

            <div class="guest-card dark:bg-gray-800">
                {{ $slot }}
            </div>

            @if (!$minimal)
                <x-unofficial-notice />
            @endif
        </div>
    </body>
</html>
