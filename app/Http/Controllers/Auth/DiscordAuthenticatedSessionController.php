<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DiscordIdentity;
use App\Support\DiscordAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DiscordAuthenticatedSessionController extends Controller
{
    private const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    private const USER_URL = 'https://discord.com/api/users/@me';

    public function redirect(Request $request): RedirectResponse
    {
        $discord = config('services.discord');
        if (empty($discord['client_id']) || empty($discord['client_secret']) || empty($discord['redirect'])) {
            abort(503, 'Discord sign-in is not configured.');
        }

        $state = Str::random(64);
        $request->session()->put('discord_oauth_state', $state);
        $request->session()->put('discord_oauth_intent', $request->query('intent') === 'register' ? 'register' : 'login');

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $discord['client_id'],
            'redirect_uri' => $discord['redirect'],
            'response_type' => 'code',
            'scope' => 'identify',
            'state' => $state,
            'prompt' => 'consent',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->loginError('Discord sign-in was cancelled or denied.');
        }

        $state = (string) $request->session()->pull('discord_oauth_state', '');
        if ($state === '' || !hash_equals($state, (string) $request->query('state', ''))) {
            return $this->loginError('Discord sign-in could not be verified. Please try again.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->loginError('Discord did not return a sign-in code.');
        }

        $intent = (string) $request->session()->pull('discord_oauth_intent', 'login');
        $discord = config('services.discord');
        try {
            $token = Http::asForm()
                ->withBasicAuth((string) $discord['client_id'], (string) $discord['client_secret'])
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $discord['redirect'],
                ])
                ->throw()
                ->json('access_token');

            $discordUser = Http::withToken((string) $token)
                ->timeout(10)
                ->get(self::USER_URL)
                ->throw()
                ->json();
        } catch (\Throwable) {
            return $this->loginError('Discord sign-in failed. Please try again.');
        }

        $discordId = $discordUser['id'] ?? null;
        if (!is_string($discordId) || !preg_match('/^\d{15,25}$/', $discordId)) {
            return $this->loginError('Discord returned an invalid account identity.');
        }

        $discordAvatarUrl = DiscordAvatar::url($discordId, is_string($discordUser['avatar'] ?? null) ? $discordUser['avatar'] : null);

        $identity = DiscordIdentity::with('user')->where('discord_id', $discordId)->first();
        if (!$identity?->user) {
            if ($intent === 'register') {
                $request->session()->put('discord_registration_id', $discordId);
                $request->session()->put('discord_registration_avatar_url', $discordAvatarUrl);

                return redirect()->route('discord.register.name');
            }

            return $this->loginError('This Discord account has not been linked to a FarmVille account yet.');
        }

        $identity->update(['avatar_url' => $discordAvatarUrl]);
        $identity->user->userMeta()?->update([
            'profile_picture' => url('/profile-pictures/discord/'.$identity->user->uid).'?v=2',
        ]);

        Auth::login($identity->user);
        $request->session()->regenerate();

        return redirect()->intended(route('play', absolute: false));
    }

    private function loginError(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['discord' => $message]);
    }
}
