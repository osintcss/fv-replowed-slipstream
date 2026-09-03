<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div style="margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.75rem;">Before You Play</h3>
                        <p style="margin-bottom: 1rem; line-height: 1.6;">This is an old Flash-based game. To play it, you need to install both of the following on your PC. We only support <strong>Windows 10</strong>.</p>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                            <a href="https://downloads.los-network.com/files/flashplayer32_0r0_371_win.exe"
                                style="background: linear-gradient(135deg, #2d5016, #4a7c23); color: #fff; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: opacity 0.15s; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                <span style="font-size: 1.1rem;">&#x1F4E5;</span> 1. Download Flash Player (Windows)
                            </a>
                            <a href="https://downloads.los-network.com/files/palemoon-33.9.1.win64.installer.exe"
                                style="background: linear-gradient(135deg, #2d5016, #4a7c23); color: #fff; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: opacity 0.15s; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                <span style="font-size: 1.1rem;">&#x1F310;</span> 2. Download Pale Moon Browser (Win64)
                            </a>
                        </div>
                        <p style="font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem;">Install both, then open this site in Pale Moon to play.</p>
                    </div>

                    <hr class="border-gray-200 dark:border-gray-700 mb-6">

                    @if (is_dir(public_path('farmville/assets/hashed/assets')))
                        <h2>Assets exist. Go to the "Play" tab and enjoy!</h2>
                    @else
                        <h2>Assets don't exist.</h2>
                        <p>An administrator must run <code>make assets</code> on the server before the game can be played.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
