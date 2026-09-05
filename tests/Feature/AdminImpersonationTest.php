<?php

use App\Models\User;
use App\Models\UserMeta;

function impersonationUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    UserMeta::create([
        'uid' => $user->uid,
        'firstName' => $user->name,
        'lastName' => 'Player',
    ]);

    return $user;
}

test('an administrator can impersonate a player and return to their own account', function () {
    $administrator = impersonationUser(['is_admin' => true]);
    $player = impersonationUser(['name' => 'Anita Bucha']);

    $this->actingAs($administrator)
        ->post('/admin/impersonate', ['uid' => $player->uid])
        ->assertRedirect(route('play'));

    $this->assertAuthenticatedAs($player);
    expect(session()->get('impersonator_user_id'))->toBe($administrator->id);

    $this->post('/admin/stop-impersonating')
        ->assertRedirect(route('admin'));

    $this->assertAuthenticatedAs($administrator);
    expect(session()->has('impersonator_user_id'))->toBeFalse();
});

test('a non-administrator cannot impersonate a player', function () {
    $user = impersonationUser();
    $player = impersonationUser();

    $this->actingAs($user)
        ->post('/admin/impersonate', ['uid' => $player->uid])
        ->assertForbidden();
});

test('an administrator cannot impersonate another administrator', function () {
    $administrator = impersonationUser(['is_admin' => true]);
    $otherAdministrator = impersonationUser(['is_admin' => true]);

    $this->actingAs($administrator)
        ->from('/admin')
        ->post('/admin/impersonate', ['uid' => $otherAdministrator->uid])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('impersonation');

    $this->assertAuthenticatedAs($administrator);
});
