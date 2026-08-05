# Locale runtime prototype

This source builds a contract-only `Locale_en_US` SWF. It exports the same
public `info` and `text` fields expected by `ZLocalization.LocalizerSWF`.

It intentionally contains no production locale data yet. The next revision
embeds `en_US.locale.fvlz`, inflates it to bytes, and provides the lazy bucket
adapter after the prototype SWF is verified in Ruffle.

The prototype was compiled with Apache Flex 4.16.1 and the archived Flash
Player 27.0 `playerglobal.swc`. `playerglobal.swc` is a compiler-only API
library; it is not included in the generated SWF.

## Ruffle test route

After building the prototype, open `/play?locale_test=1`. The game uses a
test-only locale path that serves `Locale_en_US.lazy.swf` for `en_US.swf` and
falls through to the archived locale tree for every other file. Omitting the
query parameter immediately restores the original locale SWF.
