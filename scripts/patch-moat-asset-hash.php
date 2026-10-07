<?php

declare(strict_types=1);

/**
 * Adds content-addressed Turtle Back Moat rotation fixes to the external
 * asset-hash delta without modifying the recovered archive file in place.
 *
 * The client loads this AMF3 object from the XML base URL. Keep the original
 * delta intact: the revisioned copy gives Flash a fresh cache key while the
 * content hashes guarantee that the two replacement SWFs remain immutable.
 */

require_once __DIR__ . '/../public/farmville/flashservices/amfphp/ClassLoader.php';

final class MoatAssetHashDecoder extends Amfphp_Core_Amf_Deserializer
{
    public function decode(string $data): object
    {
        $this->rawData = $data;
        $this->currentByte = 0;
        $this->resetReferences();

        $decoded = $this->readAmf3Data();
        if (!is_object($decoded)) {
            throw new RuntimeException('Asset-hash delta did not decode to an AMF3 object.');
        }

        return $decoded;
    }
}

final class MoatAssetHashEncoder extends Amfphp_Core_Amf_Serializer
{
    public function encode(object $value): string
    {
        // Reference handles are encoded differently for AMF0 and AMF3. This
        // is a standalone AMF3 object, rather than an AMF packet, so provide
        // the serializer the packet context it normally receives via
        // serialize().
        $this->packet = new Amfphp_Core_Amf_Packet();
        $this->packet->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
        $this->resetReferences();
        $this->outBuffer = '';
        $this->writeAmf3Data($value);

        return $this->outBuffer;
    }
}

$catalogDirectory = __DIR__ . '/../public/farmville/xml/gz/v855038';
$sourcePath = $catalogDirectory . '/assethash.min.amf.gz';
$targetPath = $catalogDirectory . '/assethash.moatrotation1.amf.gz';
$overrides = [
    'moat_turtleback3.swf' => '374b733e9b8d38413a6e5222bbe181e8',
    'moat_turtleback4.swf' => '455d1e237b9be3e7cfc3def2bc8ff529',
];

$compressed = @file_get_contents($sourcePath);
if ($compressed === false) {
    throw new RuntimeException("Asset-hash delta not found: {$sourcePath}");
}

$raw = @gzuncompress($compressed);
if ($raw === false) {
    throw new RuntimeException("Could not decompress asset-hash delta: {$sourcePath}");
}

$decoder = new MoatAssetHashDecoder();
$assetHash = $decoder->decode($raw);
if (!isset($assetHash->decorations) || !is_object($assetHash->decorations)) {
    throw new RuntimeException('Asset-hash delta has no decorations category.');
}

foreach ($overrides as $assetName => $hash) {
    $assetHash->decorations->{$assetName} = $hash;
}

$encoder = new MoatAssetHashEncoder();
$encoded = $encoder->encode($assetHash);
$output = gzcompress($encoded, 9);
if ($output === false || file_put_contents($targetPath, $output) === false) {
    throw new RuntimeException("Could not write patched asset-hash delta: {$targetPath}");
}

$verifiedCompressed = file_get_contents($targetPath);
$verifiedRaw = $verifiedCompressed === false ? false : @gzuncompress($verifiedCompressed);
if ($verifiedRaw === false) {
    throw new RuntimeException('Could not re-read the patched asset-hash delta.');
}

$verified = $decoder->decode($verifiedRaw);
foreach ($overrides as $assetName => $hash) {
    if (($verified->decorations->{$assetName} ?? null) !== $hash) {
        throw new RuntimeException("Patched asset hash was not retained for {$assetName}.");
    }
}

echo 'Patched Turtle Back Moat asset hashes: ' . implode(', ', array_keys($overrides)) . PHP_EOL;
