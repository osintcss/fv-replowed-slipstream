package {
    import flash.utils.Proxy; import flash.utils.flash_proxy;
    public final class LazyLocaleText extends Proxy {
        private var table:FvlTable, cache:Object={};
        public function LazyLocaleText(t:FvlTable) { table=t; }
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(cache.hasOwnProperty(key)) return cache[key]; var p:int=table.packageIndex(key); return p<0?undefined:(cache[key]=new LazyLocalePackage2(table,p)); }
        override flash_proxy function hasProperty(name:*):Boolean { return table.packageIndex(String(name))>=0; }
        override flash_proxy function setProperty(name:*,value:*):void { cache[String(name)]=value; }
    }
}
