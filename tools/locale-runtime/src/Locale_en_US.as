package {
    import flash.display.Sprite;
    import flash.utils.ByteArray;

    /** Replacement root retaining the contract consumed by LocalizerSWF. */
    public class Locale_en_US extends Sprite {
        [Embed(source="../../../.cache/en_US.locale.fvl2", mimeType="application/octet-stream")]
        private static const LocaleData:Class;

        public var info:Object;
        public var text:Object;

        public function Locale_en_US() {
            super();
            var packed:ByteArray = new LocaleData() as ByteArray;
            var table:FvlTable = new FvlTable(packed);
            info = { locale: "en_US" };
            text = new LazyLocaleText(table);
        }
    }
}
