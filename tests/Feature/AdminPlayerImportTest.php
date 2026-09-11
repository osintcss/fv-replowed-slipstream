<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

test('non-administrators cannot submit the save importer', function () {
    $user = User::factory()->create();
    $upload = UploadedFile::fake()->createWithContent('payload.php', '{"format":"fv-replowed-slipstream-player-export"}');

    $this->actingAs($user)
        ->from('/admin')
        ->post(route('admin.import-save'), [
            'save_file' => $upload,
            'replace_existing_save' => '1',
        ])
        ->assertForbidden();
});

test('the admin importer rejects executable-looking filenames', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $upload = UploadedFile::fake()->createWithContent('payload.php', '{"format":"fv-replowed-slipstream-player-export"}');

    $this->actingAs($administrator)
        ->from('/admin')
        ->post(route('admin.import-save'), [
            'save_file' => $upload,
            'replace_existing_save' => '1',
        ])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('save_file');
});
