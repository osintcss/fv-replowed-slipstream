<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\RegistrationCapacity;
use App\Models\DiscordIdentity;
use App\Support\DiscordAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DiscordAuthenticatedSessionController extends Controller
{
    private const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    private const USER_URL = 'https://discord.com/api/users/@me';
    private const MEMBER_URL = 'https://discord.com/api/users/@me/guilds/%s/member';
    private const LAUNCHER_STATE_TTL = 300;
    private const LAUNCHER_HANDOFF_TTL = 60;

    public function redirect(Request $request): RedirectResponse
    {
        $discord = config('services.discord');
        if (empty($discord['client_id']) || empty($discord['client_secret']) || empty($discord['redirect'])) {
            abort(503, 'Discord sign-in is not configured.');
        }

        $state = Str::random(64);
        $request->session()->put('discord_oauth_state', $state);
        $request->session()->put('discord_oauth_intent', $request->query('intent') === 'register' ? 'register' : 'login');
        $scopes = ['identify'];
        if ((string) ($discord['required_guild_id'] ?? '') !== '') {
            $scopes[] = 'guilds.members.read';
        }

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $discord['client_id'],
            'redirect_uri' => $discord['redirect'],
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'prompt' => 'consent',
        ]));
    }

    public function launcherRedirect(Request $request): RedirectResponse
    {
        $discord = config('services.discord');
        if (empty($discord['client_id']) || empty($discord['client_secret']) || empty($discord['redirect'])) {
            abort(503, 'Discord sign-in is not configured.');
        }

        $state = (string) $request->query('state', '');
        $callback = (string) $request->query('callback', '');
        $intent = $request->query('intent') === 'register' ? 'register' : 'login';

        if (!$this->validLauncherState($state) || !$this->validLauncherCallback($callback)) {
            abort(400, 'Invalid launcher sign-in request.');
        }

        Cache::put($this->launcherStateKey($state), [
            'callback' => $callback,
            'intent' => $intent,
        ], now()->addSeconds(self::LAUNCHER_STATE_TTL));

        return redirect()->away($this->discordAuthorizeUrl($state, $intent));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $launcherRequest = $this->validLauncherState($state)
            ? Cache::pull($this->launcherStateKey($state))
            : null;

        if (is_array($launcherRequest)) {
            return $this->launcherCallback($request, $state, $launcherRequest);
        }

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

        // The front gate has one Discord button. After membership is verified,
        // an existing identity logs in and an unlinked identity starts signup.
        // Ignore any stale intent from older clients; account state determines
        // which flow the user needs.
        $request->session()->forget('discord_oauth_intent');
        $result = $this->resolveDiscordCode($code);
        if (isset($result['error'])) {
            return $this->loginError($result['error']);
        }

        $identity = $result['identity'];
        if (!$identity?->user) {
            if (RegistrationCapacity::isFull()) {
                return $this->loginError('Registration is currently full.');
            }

            $request->session()->put('discord_registration_id', $result['discord_id']);
            $request->session()->put('discord_registration_avatar_url', $result['avatar_url']);

            return redirect()->route('discord.register.name');
        }

        $this->updateIdentityAvatar($identity, $result['avatar_url']);

        Auth::login($identity->user);
        $request->session()->regenerate();
        $this->markMembershipVerified($request);

        return redirect()->intended(route('play', absolute: false));
    }

    public function launcherConsume(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            return $this->loginError('Launcher sign-in expired. Please try again.');
        }

        $handoff = Cache::pull($this->launcherHandoffKey($token));
        if (!is_array($handoff) || !array_key_exists('user_id', $handoff)) {
            return $this->loginError('Launcher sign-in expired. Please try again.');
        }

        if (($handoff['intent'] ?? 'login') === 'register') {
            if (RegistrationCapacity::isFull()) {
                return $this->loginError('Registration is currently full.');
            }

            if (!preg_match('/^\d{15,25}$/', (string) ($handoff['discord_id'] ?? ''))
                || !is_string($handoff['avatar_url'] ?? null)) {
                return $this->loginError('Launcher sign-in could not be completed. Please try again.');
            }

            $request->session()->put('discord_registration_id', $handoff['discord_id']);
            $request->session()->put('discord_registration_avatar_url', $handoff['avatar_url']);

            return redirect()->route('discord.register.name');
        }

        $user = \App\Models\User::find($handoff['user_id']);
        if (!$user) {
            return $this->loginError('Launcher sign-in could not be completed. Please try again.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->markMembershipVerified($request);

        return redirect()->route('play');
    }

    private function launcherCallback(Request $request, string $state, array $launcherRequest): RedirectResponse
    {
        $callback = (string) ($launcherRequest['callback'] ?? '');
        $params = ['state' => $state];

        if ($request->filled('error')) {
            $params['error'] = 'Discord sign-in was cancelled or denied.';

            return redirect()->away($this->launcherCallbackUrl($callback, $params));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            $params['error'] = 'Discord did not return a sign-in code.';

            return redirect()->away($this->launcherCallbackUrl($callback, $params));
        }

        $result = $this->resolveDiscordCode($code);
        if (isset($result['error'])) {
            $params['error'] = $result['error'];

            return redirect()->away($this->launcherCallbackUrl($callback, $params));
        }

        $identity = $result['identity'];
        $handoffToken = Str::random(64);
        if ($identity?->user) {
            $this->updateIdentityAvatar($identity, $result['avatar_url']);
            $handoff = ['user_id' => $identity->user_id];
        } else {
            // The launcher starts at the same single-button front gate as the
            // browser. An unlinked but guild-approved Discord account belongs
            // in the registration flow automatically.
            $handoff = [
                'intent' => 'register',
                'discord_id' => $result['discord_id'],
                'avatar_url' => $result['avatar_url'],
                'user_id' => null,
            ];
        }
        Cache::put($this->launcherHandoffKey($handoffToken), $handoff, now()->addSeconds(self::LAUNCHER_HANDOFF_TTL));
        $params['token'] = $handoffToken;

        return redirect()->away($this->launcherCallbackUrl($callback, $params));
    }

    private function resolveDiscordCode(string $code): array
    {
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

            if (!is_string($token) || $token === '') {
                return ['error' => 'Discord sign-in failed. Please try again.'];
            }

            $discordUser = Http::withToken($token)
                ->timeout(10)
                ->get(self::USER_URL)
                ->throw()
                ->json();
        } catch (\Throwable) {
            return ['error' => 'Discord sign-in failed. Please try again.'];
        }

        $discordId = $discordUser['id'] ?? null;
        if (!is_string($discordId) || !preg_match('/^\d{15,25}$/', $discordId)) {
            return ['error' => 'Discord returned an invalid account identity.'];
        }

        $requiredGuildId = (string) ($discord['required_guild_id'] ?? '');
        if ($requiredGuildId !== '') {
            if (!preg_match('/^\d{15,25}$/', $requiredGuildId)) {
                report(new \UnexpectedValueException('DISCORD_REQUIRED_GUILD_ID must be a Discord snowflake.'));

                return ['error' => 'Discord sign-in is not configured correctly.'];
            }

            try {
                $membership = Http::withToken($token)
                    ->timeout(10)
                    ->get(sprintf(self::MEMBER_URL, $requiredGuildId));
            } catch (\Throwable) {
                return ['error' => 'Discord server membership could not be verified. Please try again.'];
            }

            if ($membership->status() === 404) {
                return ['error' => 'Join the FV Discord server before signing in.'];
            }

            if (!$membership->successful()) {
                return ['error' => 'Discord server membership could not be verified. Please try again.'];
            }
        }

        $avatarUrl = DiscordAvatar::url($discordId, is_string($discordUser['avatar'] ?? null) ? $discordUser['avatar'] : null);

        return [
            'identity' => DiscordIdentity::with('user')->where('discord_id', $discordId)->first(),
            'discord_id' => $discordId,
            'avatar_url' => $avatarUrl,
        ];
    }

    private function updateIdentityAvatar(DiscordIdentity $identity, string $avatarUrl): void
    {
        $identity->update(['avatar_url' => $avatarUrl]);
        $identity->user->userMeta()?->update([
            'profile_picture' => url('/profile-pictures/discord/'.$identity->user->uid).'?v=2',
        ]);
    }

    private function discordAuthorizeUrl(string $state, string $intent): string
    {
        $discord = config('services.discord');
        $scopes = ['identify'];
        if ((string) ($discord['required_guild_id'] ?? '') !== '') {
            $scopes[] = 'guilds.members.read';
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $discord['client_id'],
            'redirect_uri' => $discord['redirect'],
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'prompt' => 'consent',
        ]);
    }

    private function validLauncherState(string $state): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $state) === 1;
    }

    private function validLauncherCallback(string $callback): bool
    {
        $parts = parse_url($callback);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1'
            && isset($parts['port'])
            && $parts['port'] >= 1024
            && $parts['port'] <= 65535
            && ($parts['path'] ?? null) === '/callback'
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }

    private function launcherCallbackUrl(string $callback, array $params): string
    {
        return $callback.'?'.http_build_query($params);
    }

    private function launcherStateKey(string $state): string
    {
        return 'discord-launcher-state:'.$state;
    }

    private function launcherHandoffKey(string $token): string
    {
        return 'discord-launcher-handoff:'.hash('sha256', $token);
    }

    private function markMembershipVerified(Request $request): void
    {
        if ((string) config('services.discord.required_guild_id', '') !== '') {
            $request->session()->put('discord_membership_verified_at', now()->timestamp);
        }
    }

    private function loginError(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['discord' => $message]);
    }
}
