<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDiscordGuildMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        // This permits local installations that have not opted into the gate.
        $requiredGuildId = (string) config('services.discord.required_guild_id', '');
        if ($requiredGuildId === '') {
            return $next($request);
        }

        $verifiedAt = $request->session()->get('discord_membership_verified_at');
        $ttlMinutes = max(1, (int) config('services.discord.membership_verification_minutes', 15));
        $verified = is_numeric($verifiedAt)
            && ((int) $verifiedAt + ($ttlMinutes * 60)) > now()->timestamp;

        if ($verified) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->forget('discord_membership_verified_at');
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'discord' => 'Your Discord server membership needs to be verified again.',
        ]);
    }
}
