<?php

use App\Models\PlayerMeta;
use App\Models\User;
use App\Models\UserMeta;
use App\Http\Controllers\NeighborController;

test('neighbor requests are accepted automatically by default', function () {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();

    $response = $this->actingAs($sender)
        ->postJson('/neighbors/send-request', ['neighbor_id' => $receiver->uid]);

    $response->assertOk()->assertJson([
        'success' => true,
        'accepted' => true,
    ]);

    expect(unserialize(PlayerMeta::getValue($sender->uid, 'current_neighbors'), ['allowed_classes' => false]))
        ->toContain($receiver->uid);
    expect(unserialize(PlayerMeta::getValue($receiver->uid, 'current_neighbors'), ['allowed_classes' => false]))
        ->toContain($sender->uid);
    expect(PlayerMeta::getValue($receiver->uid, 'pending_neighbors'))->toBeFalse();
});

test('a receiver can disable automatic neighbor acceptance', function () {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    PlayerMeta::setValue($receiver->uid, 'auto_accept_neighbor_requests', '0');

    $response = $this->actingAs($sender)
        ->postJson('/neighbors/send-request', ['neighbor_id' => $receiver->uid]);

    $response->assertOk()->assertJson([
        'success' => true,
        'message' => 'Neighbor request sent successfully',
    ]);

    expect(PlayerMeta::getValue($receiver->uid, 'current_neighbors'))->toBeFalse();
    expect(unserialize(PlayerMeta::getValue($receiver->uid, 'pending_neighbors'), ['allowed_classes' => false]))
        ->toContain($sender->uid);
});

test('neighbor acceptance preference is saved from settings', function () {
    $user = User::factory()->create();
    UserMeta::create([
        'uid' => $user->uid,
        'firstName' => 'Test',
        'lastName' => 'User',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/profile/settings', [
            'auto_accept_neighbor_requests' => false,
        ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'autoAcceptNeighborRequests' => false,
    ]);
    expect(NeighborController::autoAcceptNeighborRequests($user->uid))->toBeFalse();
});
