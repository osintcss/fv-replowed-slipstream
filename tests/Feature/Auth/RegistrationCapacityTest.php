<?php

use App\Models\User;
use App\Support\RegistrationCapacity;

test('registration is unavailable once the account cap is reached', function () {
    User::factory()->count(RegistrationCapacity::MAX_USERS)->create();

    expect(RegistrationCapacity::isFull())->toBeTrue();
    $this->get('/register')
        ->assertOk()
        ->assertSee('Registration is currently full.');
});
