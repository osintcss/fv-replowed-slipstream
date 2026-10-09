<?php

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
});

it('uses the authentic completed terrain configuration for Winter Fable', function (): void {
    expect(getTileSetForWorld('winternord'))->toBe('winternord_theme');
});

it('maps the early expansion worlds to their themed terrain configurations', function (): void {
    expect(getTileSetForWorld('asia'))->toBe('asia')
        ->and(getTileSetForWorld('hawaii'))->toBe('hawaii');
});

it('supplies scaled terrain maps for the early expansion worlds', function (): void {
    $hawaii = getApproximateWorldTerrain('hawaii', 74, 74);
    $asia = getApproximateWorldTerrain('asia', 50, 50);

    expect(count($hawaii))->toBe(37 * 37)
        ->and(count($asia))->toBe(25 * 25)
        ->and(array_diff(array_unique($hawaii), [1, 2, 3]))->toBe([])
        ->and(array_diff(array_unique($asia), [1, 2, 3, 4]))->toBe([])
        ->and(in_array(2, $hawaii, true))->toBeTrue()
        ->and(in_array(3, $hawaii, true))->toBeTrue()
        ->and(in_array(2, $asia, true))->toBeTrue()
        ->and(in_array(4, $asia, true))->toBeTrue();
});

it('supplies a complete land terrain map for Maple Frontier at every farm size', function (): void {
    foreach ([50, 74] as $size) {
        $terrain = getApproximateWorldTerrain('canada', $size, $size);

        expect(count($terrain))->toBe(intdiv($size, 2) ** 2)
            ->and(array_unique($terrain))->toBe([1]);
    }
});

it('uses the authored Glen land, shoreline, and water base mask', function (): void {
    $terrain = getApproximateWorldTerrain('glen', 74, 74);
    $width = 37;
    $baseOffset = $width - 25;
    $baseRow = $baseOffset * $width;

    expect(count($terrain))->toBe($width * $width)
        ->and(array_diff(array_unique($terrain), [1, 2, 3]))->toBe([])
        ->and($terrain[$baseRow + $baseOffset + 18])->toBe(1)
        ->and($terrain[$baseRow + $baseOffset + 19])->toBe(3)
        ->and($terrain[$baseRow + $baseOffset + 20])->toBe(2)
        ->and($terrain[$baseRow + $baseOffset + 24])->toBe(2)
        ->and($terrain[0])->toBe(1);
});

it('applies the traced Glen expansion shoreline through negative y coordinates', function (): void {
    $terrain = getApproximateWorldTerrain('glen', 170, 170);
    $width = 85;
    $offset = $width - 25;

    $cell = static function (int $x, int $y) use ($terrain, $width, $offset): int {
        return $terrain[($y + $offset) * $width + $x + $offset];
    };

    expect($cell(19, -1))->toBe(3)
        ->and($cell(20, -1))->toBe(2)
        ->and($cell(18, -2))->toBe(3)
        ->and($cell(19, -2))->toBe(2)
        ->and($cell(16, -13))->toBe(3)
        ->and($cell(17, -13))->toBe(2)
        ->and($cell(15, -21))->toBe(3)
        ->and($cell(16, -21))->toBe(2)
        ->and($cell(14, -22))->toBe(3)
        ->and($cell(15, -22))->toBe(2)
        ->and($cell(13, -25))->toBe(3)
        ->and($cell(14, -25))->toBe(2)
        ->and($cell(14, -29))->toBe(3)
        ->and($cell(15, -29))->toBe(2)
        ->and($cell(13, -31))->toBe(3)
        ->and($cell(14, -31))->toBe(2)
        ->and($cell(12, -32))->toBe(3)
        ->and($cell(13, -32))->toBe(2)
        ->and($cell(11, -33))->toBe(3)
        ->and($cell(12, -33))->toBe(2)
        ->and($cell(11, -40))->toBe(3)
        ->and($cell(12, -40))->toBe(2)
        ->and($cell(10, -43))->toBe(3)
        ->and($cell(11, -43))->toBe(2)
        ->and($cell(9, -45))->toBe(3)
        ->and($cell(10, -45))->toBe(2)
        ->and($cell(8, -51))->toBe(3)
        ->and($cell(9, -51))->toBe(2)
        ->and($cell(7, -53))->toBe(3)
        ->and($cell(8, -53))->toBe(2)
        ->and($cell(6, -55))->toBe(3)
        ->and($cell(7, -55))->toBe(2)
        ->and($cell(15, -1))->toBe(1);
});

it('uses an invisible land mask for Atlantis until its map is recovered', function (): void {
    $terrain = getApproximateWorldTerrain('atlantis', 74, 74);

    expect(count($terrain))->toBe(37 * 37)
        ->and(array_unique($terrain))->toBe([1]);
});

it('keeps the Jade Falls authored mask rectangular and row-major', function (): void {
    $terrain = getAuthoredJadeFallsTerrain(50, 50);

    expect(count($terrain))->toBe(625)
        ->and(array_diff(array_unique($terrain), [1, 2, 3, 4]))->toBe([])
        ->and($terrain[0])->toBe(2)
        ->and($terrain[1 * 25 + 9])->toBe(3)
        ->and($terrain[11 * 25 + 15])->toBe(3)
        ->and($terrain[17 * 25])->toBe(4);
});

it('keeps the authored Jade Falls base fixed when the world expands', function (): void {
    $terrain = getApproximateWorldTerrain('asia', 146, 146);
    $width = 73;
    $baseOffset = 48;

    expect(count($terrain))->toBe($width * $width)
        // The original scene cells retain their logical coordinates after
        // the raw terrain array grows.
        ->and($terrain[$baseOffset * $width + $baseOffset])->toBe(2)
        ->and($terrain[($baseOffset + 1) * $width + $baseOffset + 9])->toBe(3)
        ->and($terrain[($baseOffset + 11) * $width + $baseOffset + 15])->toBe(3)
        ->and($terrain[($baseOffset + 17) * $width + $baseOffset])->toBe(4)
        // Newly exposed cells use the sparse authored map where available.
        ->and($terrain[0])->toBe(2)
        ->and($terrain[47 * $width + 47])->toBe(2)
        ->and($terrain[72 * $width])->toBe(1);

    $expansion = getJadeFallsExpansionTerrain();
    $codes = ['L' => 1, 'W' => 2, 'S' => 3, 'T' => 4];

    for ($y = 0; $y < $width; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $insideBase = $x >= $baseOffset && $x < $baseOffset + 25
                && $y >= $baseOffset && $y < $baseOffset + 25;

            if (!$insideBase) {
                $sceneX = $x - $baseOffset;
                $sceneY = $y - $baseOffset;
                $expected = isset($expansion["$sceneX,$sceneY"])
                    ? $codes[$expansion["$sceneX,$sceneY"]]
                    : 1;

                expect($terrain[$y * $width + $x])->toBe($expected);
            }
        }
    }
});

it('applies the hand-authored Jade Falls river to negative scene coordinates', function (): void {
    $expansion = getJadeFallsExpansionTerrain();

    expect($expansion['-6,0'])->toBe('S')
        ->and($expansion['-5,0'])->toBe('W')
        ->and($expansion['-1,0'])->toBe('W')
        ->and($expansion['-2,4'])->toBe('S')
        ->and($expansion['-6,-1'])->toBe('W')
        ->and($expansion['6,-1'])->toBe('W')
        ->and($expansion['-7,-1'])->toBe('S')
        ->and($expansion['7,-1'])->toBe('S')
        ->and($expansion['-13,-8'])->toBe('W')
        ->and($expansion['0,-8'])->toBe('W')
        ->and($expansion['-14,-8'])->toBe('S')
        ->and($expansion['-40,-39'])->toBe('W')
        ->and($expansion['-21,-39'])->toBe('S')
        ->and($expansion['-48,-48'])->toBe('W')
        ->and($expansion['24,-47'])->toBe('W')
        ->and($expansion['-42,-8'])->toBe('T')
        ->and($expansion['-48,-14'])->toBe('T')
        ->and($expansion['-34,0'])->toBe('T')
        ->and($expansion['-24,10'])->toBe('T')
        ->and($expansion['-10,24'])->toBe('T')
        ->and($expansion['-48,4'])->toBe('T')
        ->and($expansion['-44,8'])->toBe('T')
        ->and($expansion['-40,12'])->toBe('T')
        ->and($expansion['-28,24'])->toBe('T')
        ->and($expansion['19,-22'])->toBe('S')
        ->and($expansion['17,-39'])->toBe('S')
        ->and($expansion['12,-42'])->toBe('S')
        ->and($expansion['22,-22'])->toBe('T')
        ->and($expansion['23,-27'])->toBe('T')
        ->and($expansion['24,-36'])->toBe('T')
        ->and($expansion['21,-27'])->toBe('T')
        ->and($expansion['20,-3'])->toBe('S')
        ->and($expansion['24,-46'])->toBe('W');
});

it('uses the sparse river overlay without changing the original Jade Falls base', function (): void {
    $terrain = getApproximateWorldTerrain('asia', 146, 146);
    $width = 73;
    $baseOffset = 48;

    // The negative scene coordinate (-6,0) is raw x=42, y=48.
    expect($terrain[$baseOffset * $width + $baseOffset - 6])->toBe(3)
        // The negative scene coordinate (-5,0) is raw x=43, y=48.
        ->and($terrain[$baseOffset * $width + $baseOffset - 5])->toBe(2)
        // The original (0,0) base cell remains water.
        ->and($terrain[$baseOffset * $width + $baseOffset])->toBe(2);
});
