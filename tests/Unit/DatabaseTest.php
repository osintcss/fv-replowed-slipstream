<?php

use App\Support\Database;
use App\Support\DatabaseOperationException;
use Illuminate\Database\QueryException;

test('database wrapper preserves a missing record result', function () {
    expect(Database::run('find test record', static fn () => null))->toBeNull();
});

test('database wrapper provides operation context for query failures', function () {
    $queryException = new QueryException(
        'mysql',
        'select * from player_meta where uid = ?',
        [42],
        new RuntimeException('connection lost'),
    );

    Database::run('find player metadata', static function () use ($queryException): void {
        throw $queryException;
    });
})->throws(DatabaseOperationException::class, 'Database operation failed: find player metadata');
