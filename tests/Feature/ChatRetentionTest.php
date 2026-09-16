<?php

use App\Models\ChatMessage;
use App\Models\User;

it('retains old chat messages when the legacy cleanup command is invoked', function (): void {
    $user = User::factory()->create();
    $message = ChatMessage::query()->create([
        'uid' => $user->uid,
        'message' => 'This message must be retained.',
    ]);
    $message->forceFill(['created_at' => now()->subYears(5)])->save();

    $this->artisan('chat:cleanup')
        ->expectsOutput('Chat retention is disabled; no messages deleted.')
        ->assertExitCode(0);

    expect(ChatMessage::query()->find($message->id))->not->toBeNull();
});
