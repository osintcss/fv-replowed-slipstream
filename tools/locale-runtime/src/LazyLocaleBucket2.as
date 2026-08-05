package {
    import flash.utils.Proxy; import flash.utils.flash_proxy;
    public final class LazyLocaleBucket2 extends Proxy {
        private var table:FvlTable,index:uint,cache:Object={},lookup:Object={}; public function LazyLocaleBucket2(t:FvlTable,i:uint){table=t;index=i;}
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(cache.hasOwnProperty(key)) return cache[key]; var found:int=resolve(key); return found<0?undefined:(cache[key]=table.entryAt(found)); }
        override flash_proxy function hasProperty(name:*):Boolean { return resolve(String(name))>=0; }
        override flash_proxy function setProperty(name:*,value:*):void { cache[String(name)]=value; }
        private function resolve(key:String):int { if(lookup.hasOwnProperty(key)) return int(lookup[key]); var found:int=table.find(index,key); lookup[key]=found; return found; }
    }
}
