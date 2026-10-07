<?php

use App\Support\AmfAuthToken;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'amf.auth_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'amf.token_ttl_seconds' => 36000,
    ]);
});

test('an AMF token authenticates its UID for ten hours', function () {
    $issuedAt = 1_700_000_000;
    $token = AmfAuthToken::issue('4097859426', $issuedAt);

    expect(AmfAuthToken::verifyForClaimedUid($token, '4097859426', $issuedAt + 35999))
        ->toBe('4097859426');
});

test('an AMF token can bind a session to a world without changing its UID', function () {
    $issuedAt = 1_700_000_000;
    $token = AmfAuthToken::issue('4097859426', $issuedAt, 'winternord');

    expect(AmfAuthToken::verifyClaimsForClaimedUid($token, '4097859426', $issuedAt + 35999))
        ->toMatchArray([
            'uid' => '4097859426',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 36000,
            'world_type' => 'winternord',
        ]);
});

test('changing an AMF world context preserves the original token lifetime', function () {
    $issuedAt = 1_700_000_000;
    $token = AmfAuthToken::issue('4097859426', $issuedAt, 'farm');
    $updated = AmfAuthToken::withWorldType($token, '4097859426', 'fforest', $issuedAt + 100);

    expect(AmfAuthToken::verifyClaims($updated, $issuedAt + 35999))
        ->toMatchArray([
            'uid' => '4097859426',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 36000,
            'world_type' => 'fforest',
        ]);

    AmfAuthToken::verify($updated, $issuedAt + 36000);
})->throws(RuntimeException::class, 'Unauthorized AMF request.');

test('an AMF token expires after ten hours', function () {
    $issuedAt = 1_700_000_000;
    $token = AmfAuthToken::issue('4097859426', $issuedAt);

    AmfAuthToken::verify($token, $issuedAt + 36000);
})->throws(RuntimeException::class, 'Unauthorized AMF request.');

test('an AMF token rejects a different claimed UID', function () {
    $token = AmfAuthToken::issue('4097859426', 1_700_000_000);

    AmfAuthToken::verifyForClaimedUid($token, '1000000001', 1_700_000_100);
})->throws(RuntimeException::class, 'Unauthorized AMF request.');

test('an AMF token rejects tampering', function () {
    $token = AmfAuthToken::issue('4097859426', 1_700_000_000);
    $token[10] = $token[10] === 'A' ? 'B' : 'A';

    AmfAuthToken::verify($token, 1_700_000_100);
})->throws(RuntimeException::class, 'Unauthorized AMF request.');

test('an AMF token rejects missing credentials', function () {
    AmfAuthToken::verifyForClaimedUid('', '4097859426');
})->throws(RuntimeException::class, 'Unauthorized AMF request.');
