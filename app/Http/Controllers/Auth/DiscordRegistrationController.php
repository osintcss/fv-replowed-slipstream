<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DiscordIdentity;
use App\Models\User;
use App\Models\UserAvatar;
use App\Models\UserMeta;
use App\Support\RegistrationCapacity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DiscordRegistrationController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (RegistrationCapacity::isFull()) {
            return redirect()->route('login')->withErrors([
                'discord' => 'Registration is currently full.',
            ]);
        }

        if (!$this->pendingDiscordId(request())) {
            return redirect()->route('register')->withErrors([
                'discord' => 'Start by signing in with Discord.',
            ]);
        }

        return view('auth.discord-register');
    }

    public function store(Request $request): RedirectResponse
    {
        $discordId = $this->pendingDiscordId($request);
        if (!$discordId) {
            return redirect()->route('register')->withErrors([
                'discord' => 'Your Discord sign-in expired. Please try again.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
        ]);
        $name = trim((string) preg_replace('/\s+/', ' ', $validated['name']));
        if ($name === '') {
            return back()->withErrors(['name' => 'Choose an in-game name.']);
        }

        try {
            $avatarUrl = (string) $request->session()->get('discord_registration_avatar_url', '');
            $user = DB::transaction(function () use ($discordId, $name, $avatarUrl): User {
                RegistrationCapacity::ensureAvailable();

                if (DiscordIdentity::where('discord_id', $discordId)->exists()) {
                    throw new \RuntimeException('This Discord account is already linked to a FarmVille account.');
                }

                $user = $this->createUser($name);
                [$firstName, $lastName] = $this->nameParts($name);

                UserMeta::create([
                    'uid' => $user->uid,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'profile_picture' => url('/profile-pictures/discord/'.$user->uid).'?v=2',
                ]);
                UserAvatar::create(['uid' => $user->uid]);
                DiscordIdentity::create([
                    'user_id' => $user->id,
                    'discord_id' => $discordId,
                    'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                    'linked_at' => now(),
                ]);

                return $user;
            });
        } catch (\RuntimeException $exception) {
            return redirect()->route('login')->withErrors(['discord' => $exception->getMessage()]);
        }

        $request->session()->forget(['discord_registration_id', 'discord_registration_avatar_url']);
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        if ((string) config('services.discord.required_guild_id', '') !== '') {
            $request->session()->put('discord_membership_verified_at', now()->timestamp);
        }

        return redirect()->route('play');
    }

    private function pendingDiscordId(Request $request): ?string
    {
        $discordId = (string) $request->session()->get('discord_registration_id', '');

        return preg_match('/^\d{15,25}$/', $discordId) ? $discordId : null;
    }

    private function createUser(string $name): User
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return User::create([
                    'uid' => (string) random_int(1_111_111_111, 9_999_999_999),
                    'name' => $name,
                    'email' => null,
                    'password' => null,
                ]);
            } catch (QueryException $exception) {
                if ($exception->errorInfo[1] !== 1062 || !str_contains($exception->getMessage(), 'uid')) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to create a unique FarmVille ID. Please try again.');
    }

    private function nameParts(string $name): array
    {
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
