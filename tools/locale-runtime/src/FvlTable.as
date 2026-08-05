package {
    import flash.utils.ByteArray;
    import flash.utils.Endian;
    public final class FvlTable {
        public var bytes:ByteArray;
        private var strings:uint, packages:uint, buckets:uint, entries:uint, variants:uint, offsets:uint, blob:uint, packageOffset:uint, bucketOffset:uint, entryOffset:uint, variantOffset:uint;
        public function FvlTable(raw:ByteArray) {
            bytes=raw; bytes.endian=Endian.LITTLE_ENDIAN; if(str(0,4)!="FVL3"||u16(4)!=3) throw new Error("Invalid FVL3 locale data");
            strings=u32(8); packages=u32(12); buckets=u32(16); entries=u32(20); variants=u32(24); offsets=32; blob=offsets+4*(strings+1); packageOffset=blob+u32(28); bucketOffset=packageOffset+12*packages; entryOffset=bucketOffset+8*buckets; variantOffset=entryOffset+24*entries;
            if(variantOffset+8*variants!=bytes.length) throw new Error("Invalid FVL3 table length");
        }
        public function packageIndex(name:String):int { for(var i:uint=0;i<packages;i++) if(stringAt(u32(packageOffset+12*i))==name) return i; return -1; }
        public function packageLength(index:uint):uint { return u32(packageOffset+12*index+4); }
        public function bucket(index:uint, number:uint):uint { return u32(packageOffset+12*index+8)+number; }
        public function find(bucketIndex:uint,key:String):int { var base:uint=bucketOffset+8*bucketIndex, first:uint=u32(base), count:uint=u32(base+4), hash:uint=hashString(key); for(var i:uint=0;i<count;i++){var at:uint=entryOffset+24*(first+i); if(u32(at+4)==hash&&stringAt(u32(at))==key) return first+i;} return -1; }
        public function entryAt(i:int):Object { var base:uint=entryOffset+24*i, result:Object={original:stringAt(u32(base+8))}; var gender:uint=u32(base+12); if(gender!=0xffffffff) result.gender=stringAt(gender); var first:uint=u32(base+16), count:uint=u32(base+20); if(count){var vars:Object={}; for(var n:uint=0;n<count;n++){var v:uint=variantOffset+8*(first+n); vars[stringAt(u32(v))]=stringAt(u32(v+4));} result.variations=vars;} return result; }
        private function hashString(value:String):uint { var total:uint=0; for(var i:uint=0;i<value.length;i++) total+=value.charCodeAt(i); return total; }
        private function stringAt(index:uint):String { if(index>=strings) throw new Error("Invalid FVL2 string index"); var start:uint=u32(offsets+4*index), end:uint=u32(offsets+4*(index+1)); return str(blob+start,end-start); }
        private function u16(at:uint):uint { bytes.position=at; return bytes.readUnsignedShort(); }
        private function u32(at:uint):uint { bytes.position=at; return bytes.readUnsignedInt(); }
        private function str(at:uint,size:uint):String { bytes.position=at; return bytes.readUTFBytes(size); }
    }
}
