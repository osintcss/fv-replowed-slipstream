package {
    import flash.utils.Proxy; import flash.utils.flash_proxy;
    internal class LazyLocaleNode extends Proxy {
        protected var cache:Object={};
        override flash_proxy function setProperty(name:*,value:*):void { cache[String(name)]=value; }
    }
    internal class LazyLocaleTextImpl extends LazyLocaleNode {
        protected var table:FvlTable; public function LazyLocaleTextImpl(t:FvlTable){table=t;}
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(cache.hasOwnProperty(key)) return cache[key]; var p:int=table.packageIndex(key); return p<0?undefined:(cache[key]=new LazyLocalePackage(table,p)); }
        override flash_proxy function hasProperty(name:*):Boolean { return table.packageIndex(String(name))>=0; }
    }
    internal final class LazyLocalePackage extends LazyLocaleNode {
        private var table:FvlTable, index:uint; public function LazyLocalePackage(t:FvlTable,i:uint){table=t;index=i;}
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(key=="length") return table.packageLength(index); if(cache.hasOwnProperty(key)) return cache[key]; var n:int=int(key); return n<0||n>=table.packageLength(index)?undefined:(cache[key]=new LazyLocaleBucket(table,table.bucket(index,n))); }
        override flash_proxy function hasProperty(name:*):Boolean { var key:String=String(name),n:int=int(key); return key=="length"||(n>=0&&n<table.packageLength(index)); }
    }
    internal final class LazyLocaleBucket extends LazyLocaleNode {
        private var table:FvlTable,index:uint; public function LazyLocaleBucket(t:FvlTable,i:uint){table=t;index=i;}
        override flash_proxy function getProperty(name:*):* { var key:String=String(name); if(cache.hasOwnProperty(key)) return cache[key]; var value:Object=table.entry(index,key); return value==null?undefined:(cache[key]=value); }
        override flash_proxy function hasProperty(name:*):Boolean { return table.hasEntry(index,String(name)); }
    }
}
