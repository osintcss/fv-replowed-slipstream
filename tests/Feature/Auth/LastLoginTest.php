<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('a successful login records the last login time', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    Auth::login($user);

    expect($user->refresh()->last_login_at)->not->toBeNull();
});
