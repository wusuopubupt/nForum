<?php
class Packer{

    public static $None = 0;
    public static $Numeric = 10;
    public static $Normal = 62;
    public static $HighASCII = 95;

    private $_js_encode = 0;
    /**
     * function pack compress & cache files
     *
     * @param mixed $file string|array file name
     * @param string $type compress function prefix like _{$type}_compress
     * @param boolean $type whether check cache file update
     * @return string packed file content
     * @access public
     */
    public function pack($file, $type, $check = true){
        if(is_array($file)){
            $tmp = '';
            foreach($file as $v){
                $tmp .= $this->pack($v, $type, $check);
            }
            return $tmp;
        }
        if(!file_exists($file))
            return '';
        $cachePath = CACHE . DS . "asset" . DS;
        $cache = $cachePath . substr(md5($file), 0, 10) . basename($file);
        if(file_exists($cache)){
            if(!$check || filemtime($file) > filemtime($cache)){
                file_put_contents($cache, $this->_compress($file, $type));
            }
        }else{
            file_put_contents($cache, $this->_compress($file, $type));
        }
        return file_get_contents($cache);
    }

    public function setEncode($v){
        if(in_array($v, array(0, 10, 62, 95)))
            $this->_js_encode = $v;
    }

    private function _compress($file, $type){
        $func = "_{$type}_compress";
        $content = file_get_contents($file);
        if(method_exists($this, $func)){
            $content = call_user_func(array($this,$func), $content);
        }
        return $content;
    }

    private function _js_compress($content){
        load('inc/jspacker');
        $js = new JavaScriptPacker($content, $this->_js_encode, true, false);
        $this->setEncode(0);
        return $js->pack();
    }

    private function _css_compress($content){
        $pattern = array("/\/\*[\s\S]*?\*\//"
            ,"/(\s*[\n\r]+\s*)+/"
            ,"/\s*([{}:;,])\s*/"
            ,"/;}/"
            ,"/ +/"
            ,"/(?<![\d])0px/"
        );
        $replace = array(""
            ,""
            ,"\\1"
            ,"}"
            ," "
            ,"0"
        );
        return preg_replace($pattern, $replace, $content);
    }
}
