<nav x-data="{ open: false }" style="background: linear-gradient(135deg, #1a472a, #2d5016); border-bottom: 2px solid #4a7c23;">
    <style>
        .fc-nav-link {
            display: inline-flex;
            align-items: center;
            padding: 0 0.25rem;
            border-bottom: 2px solid transparent;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: color 0.15s, border-color 0.15s;
            height: 4rem;
        }
        .fc-nav-link:hover { color: #fff; border-bottom-color: rgba(251,191,36,0.5); }
        .fc-nav-link.active { color: #fbbf24; border-bottom-color: #fbbf24; }
        .fc-dropdown-btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgba(255,255,255,0.85);
            background: transparent;
            cursor: pointer;
            transition: all 0.15s;
        }
        .fc-dropdown-btn:hover { color: #fff; border-color: rgba(255,255,255,0.4); }
        .fc-dropdown-btn svg { fill: rgba(255,255,255,0.7); height: 1rem; width: 1rem; margin-left: 0.25rem; }
        .fc-hamburger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.375rem;
            color: rgba(255,255,255,0.6);
            background: transparent;
            border: none;
            cursor: pointer;
            transition: color 0.15s;
        }
        .fc-hamburger:hover { color: #fff; }
        .fc-responsive-link {
            display: block;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }
        .fc-responsive-link:hover { color: #fff; background: rgba(255,255,255,0.05); }
        .fc-responsive-link.active { color: #fbbf24; border-left-color: #fbbf24; background: rgba(251,191,36,0.05); }
    </style>

    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" style="font-size: 1.25rem; font-weight: 700; color: #fbbf24; text-decoration: none;">
                        FV Classic
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden sm:flex" style="margin-left: 2.5rem; gap: 2rem;">
                    <a href="{{ route('dashboard') }}" class="fc-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        Dashboard
                    </a>
                    @if (is_dir(public_path('farmville/assets/hashed/assets')))
                        <a href="{{ route('game') }}" class="fc-nav-link {{ request()->routeIs('game') ? 'active' : '' }}">
                            Play
                        </a>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="fc-dropdown-btn">
                            <div>{{ Auth::user()->name }}</div>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="fc-hamburger">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden" style="background: rgba(0,0,0,0.15);">
        <div style="padding: 0.5rem 0;">
            <a href="{{ route('dashboard') }}" class="fc-responsive-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                Dashboard
            </a>
            @if (is_dir(public_path('farmville/assets/hashed/assets')))
                <a href="{{ route('game') }}" class="fc-responsive-link {{ request()->routeIs('game') ? 'active' : '' }}">
                    Play
                </a>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div style="padding: 0.75rem 0 0.25rem; border-top: 1px solid rgba(255,255,255,0.1);">
            <div style="padding: 0 1rem;">
                <div style="font-weight: 500; color: #fff;">{{ Auth::user()->name }}</div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5);">{{ Auth::user()->email }}</div>
            </div>

            <div style="margin-top: 0.75rem;">
                <a href="{{ route('profile.edit') }}" class="fc-responsive-link">Profile</a>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <a href="{{ route('logout') }}" class="fc-responsive-link"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        Log Out
                    </a>
                </form>
            </div>
        </div>
    </div>
</nav>
