<?php

test('registration screen starts the Discord sign-in flow', function () {
    $response = $this->get('/register');

    $response->assertOk()
        ->assertSee('Continue with Discord')
        ->assertSee(route('discord.redirect', ['intent' => 'register'], absolute: false));
});
