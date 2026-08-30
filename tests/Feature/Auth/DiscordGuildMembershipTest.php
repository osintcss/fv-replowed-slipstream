<?php

use App\Models\User;
use App\Models\DiscordIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;

test('discord authorization requests the guild membership scope', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
        'services.discord.required_guild_id' => '999999999999999999',
    ]);

    $response = $this->get(route('discord.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('scope=identify+guilds.members.read');
});

test('discord sign-in rejects a user outside the required guild', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
        'services.discord.required_guild_id' => '999999999999999999',
    ]);

    Http::fake([
        'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'user-token']),
        'https://discord.com/api/users/@me' => Http::response([
            'id' => '123456789012345678',
            'avatar' => null,
        ]),
        'https://discord.com/api/users/@me/guilds/999999999999999999/member' => Http::response([], 404),
    ]);

    $response = $this->withSession(['discord_oauth_state' => 'state-token'])
        ->get(route('discord.callback', ['code' => 'test-code', 'state' => 'state-token']));

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['discord' => 'Join the FV Discord server before signing in.']);
});

test('discord sign-in starts registration for an unlinked guild member', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
        'services.discord.required_guild_id' => '999999999999999999',
    ]);

    Http::fake([
        'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'user-token']),
        'https://discord.com/api/users/@me' => Http::response([
            'id' => '123456789012345678',
            'avatar' => null,
        ]),
        'https://discord.com/api/users/@me/guilds/999999999999999999/member' => Http::response([]),
    ]);

    $response = $this->withSession(['discord_oauth_state' => 'state-token'])
        ->get(route('discord.callback', ['code' => 'test-code', 'state' => 'state-token']));

    $response->assertRedirect(route('discord.register.name'));
    $this->assertGuest();
    expect(session('discord_registration_id'))->toBe('123456789012345678');
});

test('launcher discord sign-in returns a one-time handoff token', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
        'services.discord.required_guild_id' => null,
    ]);

    $user = User::factory()->create();
    DiscordIdentity::create([
        'user_id' => $user->id,
        'discord_id' => '123456789012345678',
        'linked_at' => now(),
    ]);

    $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    Cache::put('discord-launcher-state:'.$state, [
        'callback' => 'http://127.0.0.1:43127/callback',
        'intent' => 'login',
    ], 300);

    Http::fake([
        'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'user-token']),
        'https://discord.com/api/users/@me' => Http::response([
            'id' => '123456789012345678',
            'avatar' => null,
        ]),
    ]);

    $response = $this->get(route('discord.callback', ['code' => 'test-code', 'state' => $state]));
    $response->assertRedirect();

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'] ?? null)->toBe($state)
        ->and($query['token'] ?? null)->toBeString()->not->toBeEmpty();

    $consume = $this->get(route('discord.launcher.consume', ['token' => $query['token']]));
    $consume->assertRedirect(route('play'));
    $this->assertAuthenticatedAs($user);

    // The consume route is intentionally behind the guest middleware. Log
    // out before replaying the one-time token so the controller, rather than
    // RedirectIfAuthenticated, can report the expired handoff.
    Auth::logout();
    $replay = $this->get(route('discord.launcher.consume', ['token' => $query['token']]));
    $replay->assertRedirect(route('login'))
        ->assertSessionHasErrors(['discord']);
});

test('launcher discord sign-in starts registration for an unlinked account', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
        'services.discord.required_guild_id' => '999999999999999999',
    ]);

    $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    Cache::put('discord-launcher-state:'.$state, [
        'callback' => 'http://127.0.0.1:43127/callback',
        'intent' => 'login',
    ], 300);

    Http::fake([
        'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'user-token']),
        'https://discord.com/api/users/@me' => Http::response([
            'id' => '123456789012345678',
            'avatar' => null,
        ]),
        'https://discord.com/api/users/@me/guilds/999999999999999999/member' => Http::response([]),
    ]);

    $response = $this->get(route('discord.callback', ['code' => 'test-code', 'state' => $state]));
    $response->assertRedirect();

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['token'] ?? null)->toBeString()->not->toBeEmpty();

    $consume = $this->get(route('discord.launcher.consume', ['token' => $query['token']]));
    $consume->assertRedirect(route('discord.register.name'));
    $this->assertGuest();
    expect(session('discord_registration_id'))->toBe('123456789012345678');
});

test('launcher discord redirect rejects non-loopback callbacks', function () {
    config([
        'services.discord.client_id' => '123456789012345678',
        'services.discord.client_secret' => 'test-secret',
        'services.discord.redirect' => 'https://example.test/auth/discord/callback',
    ]);

    $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $this->get(route('discord.launcher.redirect', [
        'state' => $state,
        'callback' => 'http://evil.example/callback',
    ]))->assertStatus(400);
});

test('expired guild verification logs out an authenticated user', function () {
    config([
        'services.discord.required_guild_id' => '999999999999999999',
        'services.discord.membership_verification_minutes' => 15,
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)
        ->withSession(['discord_membership_verified_at' => now()->subMinutes(16)->timestamp])
        ->get('/profile');

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['discord']);
    $this->assertGuest();
});
