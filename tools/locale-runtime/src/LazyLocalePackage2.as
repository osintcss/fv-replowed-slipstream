package {
    import flash.utils.Proxy; import flash.utils.flash_proxy;
    public final class LazyLocalePackage2 extends Proxy {
        private var table:FvlTable,index:uint,cache:Object={}; public function LazyLocalePackage2(t:FvlTable,i:uint){table=t;index=i;}
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(key=="length") return table.packageLength(index); if(cache.hasOwnProperty(key)) return cache[key]; var n:int=int(key); return n<0||n>=table.packageLength(index)?undefined:(cache[key]=new LazyLocaleBucket2(table,table.bucket(index,n))); }
        override flash_proxy function hasProperty(name:*):Boolean { var key:String=String(name),n:int=int(key); return key=="length"||(n>=0&&n<table.packageLength(index)); }
        override flash_proxy function setProperty(name:*,value:*):void { cache[String(name)]=value; }
    }
}
