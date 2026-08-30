<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>FV Classic - Launcher Downloads</title>

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
                background: rgba(251, 191, 36, 0.15);
                border: 1px solid rgba(251, 191, 36, 0.3);
                color: #fbbf24;
                padding: 0.375rem 1rem;
                border-radius: 9999px;
                font-size: 0.8rem;
                font-weight: 600;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                margin-bottom: 2rem;
            }

            h1 {
                font-size: clamp(2.5rem, 6vw, 4rem);
                font-weight: 800;
                line-height: 1.1;
                margin-bottom: 1.5rem;
            }

            h1 span { color: #fbbf24; }

            .description {
                font-size: 1.125rem;
                color: rgba(255,255,255,0.75);
                line-height: 1.7;
                margin-bottom: 3rem;
            }

            .download-section {
                background: rgba(255,255,255,0.08);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 1rem;
                padding: 2.5rem;
                backdrop-filter: blur(10px);
            }

            .download-section h2 {
                font-size: 1.25rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
            }

            .download-section p {
                font-size: 0.9rem;
                color: rgba(255,255,255,0.6);
                margin-bottom: 1.5rem;
            }

            .browser-play {
                border-top: 1px solid rgba(255,255,255,0.1);
                margin-top: 2rem;
                padding-top: 2rem;
            }

            .btn-play {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #22c55e;
                color: #102a1a;
                padding: 1rem 2rem;
                border-radius: 0.5rem;
                font-size: 1rem;
                font-weight: 700;
                text-decoration: none;
                transition: all 0.2s;
            }

            .btn-play:hover {
                background: #16a34a;
                transform: translateY(-2px);
            }

            .download-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: center;
            }

            .btn-download {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                background: #fbbf24;
                color: #1a472a;
                padding: 1rem 2rem;
                border-radius: 0.5rem;
                font-size: 1rem;
                font-weight: 700;
                text-decoration: none;
                transition: all 0.2s;
            }

            .btn-download:hover {
                background: #f59e0b;
                transform: translateY(-2px);
            }

            .btn-download.windows { background: #0078D4; color: white; }
            .btn-download.windows:hover { background: #006CBC; }
            .btn-download.windows svg { display: none; }

            .btn-download.mac { background: #333; color: white; }
            .btn-download.mac:hover { background: #555; }

            .btn-download.linux { background: #E95420; color: white; }
            .btn-download.linux:hover { background: #C34113; }
            .download-buttons .mac,
            .download-buttons .linux { display: none; }

            .btn-download svg {
                width: 20px;
                height: 20px;
            }

            .requirements {
                margin-top: 2rem;
                font-size: 0.8rem;
                color: rgba(255,255,255,0.5);
            }

            .requirements ul {
                list-style: none;
                margin-top: 0.5rem;
            }

            .requirements li {
                padding: 0.25rem 0;
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
            <div class="badge">Unofficial Community Preservation Project</div>
            <h1><span>FV</span> Classic</h1>
            <p class="description">
                A community-run way to revisit the classic farming experience.
            </p>

            <x-unofficial-notice />

            <div class="download-section">
                <h2>Download Launcher</h2>
                <p>The launcher is provided through its GitHub Releases page.</p>

                <div class="download-buttons">
                    <a href="https://github.com/osintcss/fv-launcher/releases/tag/v1.0.1" target="_blank" rel="noopener noreferrer" class="btn-download windows">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801"/></svg>
                        Launcher Downloads for Windows, macOS &amp; Linux
                    </a>
                    <a href="https://github.com/osintcss/fv-launcher/releases/tag/v1.0.1" target="_blank" rel="noopener noreferrer" class="btn-download mac">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>
                        Download for macOS
                    </a>
                    <a href="https://github.com/osintcss/fv-launcher/releases/tag/v1.0.1" target="_blank" rel="noopener noreferrer" class="btn-download linux">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2zm1.37 1.706c.332 0 .733.065 1.216.399.293.2.523.269 1.052.468h.003c.255.136.405.266.478.399v-.131a.571.571 0 01.016.47c-.123.31-.516.643-1.063.842v.002c-.268.135-.501.333-.775.465-.276.135-.588.292-1.012.267a1.139 1.139 0 01-.448-.067 3.566 3.566 0 01-.322-.198c-.195-.135-.363-.332-.612-.465v-.005h-.005c-.4-.246-.616-.512-.686-.71-.07-.268-.005-.47.193-.6.224-.135.38-.271.483-.336.104-.074.143-.102.176-.131h.002v-.003c.169-.202.436-.47.839-.601.139-.036.294-.065.466-.065zm2.8 2.142c.358 1.417 1.196 3.475 1.735 4.473.286.534.855 1.659 1.102 3.024.156-.005.33.018.513.064.646-1.671-.546-3.467-1.089-3.966-.22-.2-.232-.335-.123-.335.59.534 1.365 1.572 1.646 2.757.13.535.16 1.104.021 1.67.067.028.135.06.205.067 1.032.534 1.413.938 1.23 1.537v-.002c-.06-.135-.12-.2-.184-.268-.193-.135-.406-.199-.603-.534-.268-.467-.539-.602-.937-.602a.569.569 0 00-.19.065c.438.398.62.794.64 1.206v.003c-.002.199-.038.398-.119.602-.283-.333-.502-.8-.823-.933l-.003-.003c-.09-.068-.181-.135-.262-.2h-.003a.853.853 0 00-.274-.134c-.12-.033-.283-.067-.408-.267-.04-.066-.06-.135-.06-.2v-.067c.093-.003.181-.018.272-.035.324-.068.614-.198.858-.398.238-.202.476-.465.606-.867.065-.2.097-.4.094-.6-.005-.2-.04-.4-.123-.6a1.991 1.991 0 00-.354-.53c-.78-.867-1.927-1.135-2.852-.862a.611.611 0 01.118-.067c.246-.135.402-.402.338-.6-.06-.197-.313-.336-.632-.336h-.004c-.116.002-.249.027-.393.066-.143.04-.298.106-.478.198-.38.197-.675.532-.893.935-.218.4-.36.866-.443 1.401-.083.534-.097 1.135-.044 1.802v.002c-.063 0-.124.004-.187.006-.14-.4-.333-.866-.266-1.336.06-.468.2-.869.39-1.202.188-.333.42-.602.658-.802.238-.2.476-.333.686-.4.206-.066.381-.066.465-.066l.029-.002c.006-.134.028-.268.062-.4.067-.27.17-.533.302-.8.128-.267.291-.534.503-.802l.006-.006c.089-.133.178-.267.282-.4.068-.068.135-.135.189-.202.158-.135.318-.27.49-.4l.003-.003c.066-.065.135-.135.192-.2h.003c.064-.064.128-.134.195-.199v-.003l.003-.003c.074-.069.143-.133.206-.199.088-.064.169-.134.256-.199h.009c.065-.066.125-.135.191-.2.057-.067.117-.134.165-.2l.022-.035zm-3.525 1.471c-.105.4-.166.866-.106 1.401l.003.03v.017c.003.201-.04.4-.102.602-.092.332-.263.668-.533.868-.272.2-.602.267-.941.267-.336 0-.733-.068-1.061-.403-.328-.334-.599-.733-.656-1.136-.054-.4.025-.8.176-1.135.151-.333.363-.6.608-.868h.021c.208.067.408.067.588.2.165.066.319.199.44.333l.021-.001c.082.068.169.068.258.135.085.065.172.133.248.267.074.134.147.267.165.467v-.068c-.02-.4-.208-.667-.471-.934-.28-.267-.6-.6-.748-.867a2.09 2.09 0 00-.106-.133c.205-.002.41.032.6.1l.003.003c.213.07.432.183.66.334.229.15.468.333.715.533v.001c.056.068.103.068.16.135z"/></svg>
                        Download for Linux
                    </a>
                </div>

                @auth
                    <div class="browser-play">
                        <h2>Already have Flash Player?</h2>
                        <p>Open FV Classic directly in Pale Moon or another Flash-compatible browser.</p>
                        <a href="{{ route('play') }}" class="btn-play">Play FV Classic in Browser</a>
                    </div>
                @endauth
            </div>
        </div>

        <footer class="footer">
            FV Classic &mdash; an unofficial, non-commercial community preservation project. No donations or real-world payments are accepted.
            <div class="footer-links">
                <a href="https://discord.gg/JyWugfqHkQ" target="_blank" class="footer-link">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189z"/></svg>
                    Join Discord
                </a>
                <a href="https://github.com/FV-Replowed/fv-replowed" target="_blank" class="footer-link">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                    Contribute on GitHub
                </a>
            </div>
        </footer>
    </body>
</html>
