# Lazy locale conversion plan

## Objective

Replace the eager `Locale_en_US` constructor in `en_US.swf` with a compatible
lookup layer backed by a compact, indexed locale table. The replacement must
not create the complete translation object tree at startup.

## Baseline

On 2026-08-04, Firefox reported **4,304.52 MB** immediately after a FarmVille
farm finished loading through Ruffle. This is the comparison point for every
experiment below.

The source locale asset is:

```text
public/farmville/xml/gz/v855038/en_US.swf
```

JPEXS 26.2.1 cannot be the extraction mechanism for this asset in a
memory-constrained environment: even selecting `Locale_en_US` exhausts a
1 GB JVM heap while it indexes the ABC method. The extractor must parse only
the relevant DoABC/constructor data and must not construct a full decompiler
model.

## Delivery sequence

1. **Binary table format - implemented.** `scripts/locale_binary.py` writes a
   deterministic `FVL1` file from a normalized locale tree and validates it.
2. **Constructor extractor - next.** Add a streaming SWF/ABC reader that finds
   `Locale_en_US` and symbolically evaluates only its initializer instructions
   (`pushstring`, scalar pushes, `newobject`, `newarray`, and property writes).
   It must emit the normalized tree consumed by `locale_binary.py`.
3. **Completeness gate.** Compare the extractor's object/string/instruction
   counts with the constructor report and reject unsupported instructions or
   non-string leaves. Never silently omit a translation.
4. **Runtime patch.** Replace the constructor with a small loader and a
   `flash.utils.Proxy` only if call-site tracing confirms dynamic nested
   properties are required. Prefer patching a central lookup method if one
   exists.
5. **Regression tests.** Sample known item, achievement, singular/plural, and
   missing-key lookups against both implementations. Test enumeration only if
   the game uses it.
6. **Memory comparison.** Record Firefox memory after initial farm load and at
   15 minutes using the same account and browser profile.

## Access-trace result

The production `FarmGame-10.swf` was traced with JPEXS 26.2.1 on 2026-08-04.
The relevant classes are `ZLocalization.LocaleLoader` and
`ZLocalization.LocalizerSWF`.

`LocaleLoader` loads `en_US.swf` as a SWF and constructs:

```actionscript
new LocalizerSWF(loadedMovie)
```

`LocalizerSWF` takes only `loadedMovie.info.locale` and `loadedMovie.text`.
All normal translation requests use:

```actionscript
getString(packageName, key)
```

The required `text` shape is a package dictionary with a `length` bucket count:

```text
text[packageName][bucketNumber][key] = {
  original: String,
  variations: Object?,
  gender: String?
}
```

For four or more buckets, the lookup is `hashStringAdd(key) & (length - 1)`.
`LocalizerSWF` converts an entry to a `LocalizedString` only on its first use
and caches that converted value. Therefore the patch target is a narrow
lazy `text` implementation compatible with this method; a general nested
`flash.utils.Proxy` is not required.

## `FVL1` table format

All integers are unsigned little-endian. String indexes use `0xffffffff` for
"no value".

| Field | Size | Meaning |
|---|---:|---|
| Magic | 4 | `FVL1` |
| Version | 2 | `1` |
| Flags | 2 | Reserved; zero |
| Source SHA-256 | 32 | Digest of the normalized input |
| String count | 4 | Number of deduplicated UTF-8 strings |
| Node count | 4 | Rooted dictionary nodes |
| Edge count | 4 | Key-to-child links |
| String bytes | 4 | Length of the UTF-8 blob |
| String offsets | `4 x (count + 1)` | Offsets into the blob |
| String blob | variable | Concatenated UTF-8 strings |
| Nodes | `12 x count` | `first_edge`, `edge_count`, `value_string` |
| Edges | `8 x count` | `key_string`, `child_node` |

The raw table stays compact in memory. The eventual Flash runtime will retain
the byte array and materialize only values/proxies that are actually read.

For transport, the extractor can wrap the raw table in `FVLZ`: the four-byte
magic, a four-byte little-endian uncompressed FVL1 length, then a zlib stream.
The current complete locale converts from 77,073,040 raw bytes to 19,545,945
FVLZ bytes. The Flash loader will inflate it once into bytes, never into the
original eager ActionScript object graph.

## Current tool usage

```powershell
# Produce a binary table from the normalized tree emitted by the future ABC
# extractor. Output is deterministic for the same input.
python scripts/locale_binary.py build locale-tree.json en_US.locale.bin

# Verify table integrity and print its size/counts.
python scripts/locale_binary.py inspect en_US.locale.bin

# Exercise the encoder/decoder without game assets.
python scripts/locale_binary.py selftest

# Extract the actual SWF and emit the compressed runtime artifact.
python scripts/extract_locale_abc.py public/farmville/xml/gz/v855038/en_US.swf `
  --compressed-output .cache/en_US.locale.fvlz --json
```

`locale-tree.json` must contain an object whose leaves are strings. It is an
intermediate artifact only and must not be served to clients; the binary table
is the runtime artifact.

## Non-goals for this stage

- Shipping a partially patched locale SWF.
- Loading a JSON table on every lookup.
- Treating a missing locale key as an empty string.
- Changing the game client before extraction counts and representative values
  have been verified.

## Validation and the next client-side target

The `FVL3` lazy-locale prototype was served through the isolated
`?locale_test=1` route and reached the FarmVille `loadcomplete` milestone in
Ruffle. Firefox memory immediately after the same farm loaded was about
3,100 MB, compared with the 4,304.52 MB baseline. This is a roughly 28% drop
and confirms that the locale constructor was a material memory contributor.

Ruffle's normal 15-second AVM2 watchdog can still stop this test during the
first item/configuration load. This is not because the locale proxy violates
the `LocalizerSWF` contract: with the test-only 60-second allowance, the same
client reaches `loadcomplete` without a locale exception.

The decompiled `Managers.FarmGameSettingsManager` already has a yielding
`parseItemsBatch()` routine. Its time slicing is bypassed by legacy callers
that invoke `parseItemsBatch(true, ...)`; `true` deliberately sets the batch
deadline to `int.MAX_VALUE`. The next Flash-client change must therefore
replace or defer those synchronous completion callers (beginning with the
World Score / market initialization path identified in the Ruffle stack), not
add a second parser or make every locale lookup eager.

Until that focused client patch is validated, the increased watchdog is
limited to `/play?locale_test=1`; normal `/play` retains the 15-second
duration. Do not promote the test duration as the final fix: it prevents the
watchdog symptom but does not distribute the work across frames.

### World Score investigation result

`WorldScoreSettingsManager` is still the leading synchronous-load candidate:
its AMF loader iterates the archived task data and resolves FarmItem
references immediately. A test that supplied an empty top-level AMF object
did not reach `loadcomplete`; later client code requires the World Score
configuration to exist. Therefore the production-safe fix cannot omit this
asset. It must preserve the complete configuration while deferring its
per-task item resolution, which requires a focused client-SWF/bytecode patch.
