<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Maintenance Mode - FV Classic</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Figtree', sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #1a472a 0%, #2d5016 30%, #4a7c23 60%, #2d5016 100%);
                color: #fff;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                padding: 2rem;
            }

            .container {
                text-align: center;
                max-width: 600px;
            }

            .badge {
                display: inline-block;
                background: rgba(239, 68, 68, 0.15);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #ef4444;
                padding: 0.375rem 1rem;
                border-radius: 9999px;
                font-size: 0.8rem;
                font-weight: 600;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                margin-bottom: 2rem;
            }

            .icon {
                width: 80px;
                height: 80px;
                margin: 0 auto 2rem;
                background: rgba(251, 191, 36, 0.15);
                border: 2px solid rgba(251, 191, 36, 0.3);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .icon svg {
                width: 40px;
                height: 40px;
                color: #fbbf24;
            }

            h1 {
                font-size: clamp(2rem, 5vw, 3rem);
                font-weight: 800;
                line-height: 1.1;
                margin-bottom: 1.5rem;
            }

            h1 span { color: #fbbf24; }

            .description {
                font-size: 1.125rem;
                color: rgba(255,255,255,0.75);
                line-height: 1.7;
                margin-bottom: 2rem;
            }

            .info-box {
                background: rgba(255,255,255,0.08);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 1rem;
                padding: 2rem;
                backdrop-filter: blur(10px);
            }

            .info-box p {
                font-size: 0.95rem;
                color: rgba(255,255,255,0.6);
                line-height: 1.6;
            }

            .footer {
                margin-top: 3rem;
                text-align: center;
                color: rgba(255,255,255,0.4);
                font-size: 0.8rem;
            }

            .footer a {
                color: #7289da;
                text-decoration: none;
            }

            .footer a:hover {
                color: #99aab5;
                text-decoration: underline;
            }

            .footer-links {
                display: flex;
                gap: 0.75rem;
                margin-top: 0.75rem;
                justify-content: center;
            }

            .footer-link {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                padding: 0.5rem 1rem;
                background: rgba(255,255,255,0.08);
                border: 1px solid rgba(255,255,255,0.15);
                border-radius: 0.4rem;
                color: rgba(255,255,255,0.7);
                font-size: 0.85rem;
                font-weight: 500;
                text-decoration: none;
                transition: all 0.2s;
            }

            .footer-link:hover {
                background: rgba(255,255,255,0.15);
                color: #fff;
                text-decoration: none;
            }

            .footer-link svg {
                width: 16px;
                height: 16px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="badge">Scheduled Maintenance</div>

            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
                </svg>
            </div>

            <h1><span>FV Classic</span> is under maintenance</h1>

            <p class="description">
                We're currently performing scheduled maintenance to improve your farming experience.
                Please check back soon!
            </p>

            <div class="info-box">
                <p>
                    Our team is working hard to bring you new features and improvements.
                    Thank you for your patience and understanding.
                </p>
            </div>
        </div>

        <footer class="footer">
            <strong>Unofficial community preservation project.</strong> FV Classic is not affiliated with, endorsed by, or sponsored by Zynga. We do not accept donations, subscriptions, advertising, or real-world payments.
            <div class="footer-links">
                <a href="https://discord.gg/JyWugfqHkQ" target="_blank" class="footer-link">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189z"/></svg>
                    Join Discord for Updates
                </a>
            </div>
        </footer>
    </body>
</html>
