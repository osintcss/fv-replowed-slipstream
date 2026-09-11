<?php

use App\Models\User;

it('serves a fixed-size fallback avatar when no Discord avatar is available', function (): void {
    $user = User::factory()->create();

    $response = $this->get(route('profile.discord-avatar', ['uid' => $user->uid]));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $dimensions = getimagesizefromstring($response->getContent());
    expect($dimensions[0])->toBe(50)
        ->and($dimensions[1])->toBe(50);
});
