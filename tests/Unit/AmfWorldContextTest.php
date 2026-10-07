<?php

use App\Support\AmfWorldContext;

afterEach(function (): void {
    AmfWorldContext::clear();
});

test('world context is isolated by authenticated UID', function (): void {
    AmfWorldContext::begin('4097859426', 'winternord');

    expect(AmfWorldContext::current('4097859426'))->toBe('winternord')
        ->and(AmfWorldContext::current('1000000001'))->toBeNull();
});

test('a session can switch its request context after loading another world', function (): void {
    AmfWorldContext::begin('4097859426', 'farm');
    AmfWorldContext::set('4097859426', 'fforest');

    expect(AmfWorldContext::current('4097859426'))->toBe('fforest');
});
