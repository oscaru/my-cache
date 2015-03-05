<?php

/**
 * 
 * 
 * 
 * mycacheconf.php
$config['cachePath'] = __DIR__;
$config['cacheEnabled'] = TRUE;
$config['cache_compress'] = true; // tru / false
// Page cache group
$config['cachePages'] = array (
                'default' => FALSE,
                'pageexpr' => array(
			      '/^prue/' => array('cacheType'=>'disponible','version'=>0), 
		    ), 
	);
$config['rejectPages'] = array (
                 'pageexpr' => array(
			      '/^prue/' => array('cacheType'=>'disponible','version'=>0), 
		    ), 
	);


 
 */

define ('CACHE_ACTUALIZADA', 1);
define ('ACTUALIZANDO_CACHE', 2);
if(!defined('ABSPATHCACHE') ){
    define ('ABSPATHCACHE',__DIR__);
}


/*if ( !function_exists('sem_get') ) {
    die('No hay semaforos');
    function sem_get($key) { return fopen(__FILE__.'.sem.'.$key, 'w+'); }
    function sem_acquire($sem_id) { return flock($sem_id, LOCK_EX); }
    function sem_release($sem_id) { return flock($sem_id, LOCK_UN); }
} */

if (!class_exists('CacheMeta')) {
    /**
     * estructura de metadatos para cada fichero
     */
	class MyCacheMeta {
		public $dynamic = false;
		public $headers = array();
		public $uri     = '';
		public $status  = '';   
                public $version = 0;
	}
}


if (!class_exists('RequestMeta')) {
    /**
     * estructura de metadatos para cada fichero
     */
	class RequestCacheMeta {
		public $cache_file      = '';
                public $meta_pathname   = '';
                public $uri             = '';
                public $patron          = '';
                public $cacheMetaObject = '';
                public $cacheType      = 'disponible';
                //public $semaphoreID     = '';
                
	}       
}

class MyCache {
    // Contenedor de la instancia del singleton
    private static $instance; 
    private $cachePath      = __DIR__ ;
    private $cacheEnabled  = TRUE ; 
    private $cookiesexpt    = array("/^my_cache/");//expresiones regulares para las cookies de cache
    private $file_prefix    = '';
    private $cache_max_time = 3600 ; //una hora
    private $cache_compress = TRUE;
    private $default_type   = 'disponible';
    private $version_general = 0;
    private $noCache_errors = array (E_ERROR , E_COMPILE_ERROR , E_PARSE, E_USER_ERROR );
    
    private $finish_inmediate = FALSE;
    
    private $cache_start_time = 0;
    private $returnToClient   = FALSE;
    private $known_headers = array("Last-Modified", "Expires", "Content-Type", "Content-type", "X-Pingback", "ETag", "Cache-Control", "Pragma");
    
    private $maxAcquire = 1;
    private $permissions =0666;
    private $autoRelease = 1;
 
    private $isCacheable = NULL;   
    
    private $requestMeta = FALSE;  //objeto 
    private $cacheMeta   = FALSE;  //objetoMycacheMeta
    
    public $lastPattern = ''; //ultimo patron evaluado. Para saber que patron de url desencadena la cache
    
    
    
    public function __construct($customConfig){
       if (file_exists(__DIR__.'/mycacheconf.php'))include  __DIR__.'/mycacheconf.php';
           
        if( !empty($customConfig) ){
                 foreach ($customConfig as $key => $value){
                    $config[$key] = $value;
                }
       }
       if(!empty($config)) foreach ($config as $key => $value){
            switch ($key){
                default:
                $this->{$key} = $value;
            }
        }
    }
    

    /**
     * Crea un hash único para archivo de la cache
     * @param string $uri
     * @param mixed $userid
     * @param int $version
     * @return string
     */
    private function createUniqueHash($uri,$userid = 0,$version= 0){
        $uri = md5($uri);
        return "{$uri}_{$userid}:{$version}";
        //
    }
   
   
   /**
    * obtiene el directorio final de un archivo de cache.
    * @param string $uriHash   hash del archivo
    * @return string
    */
   private function getFileCachePath($uriHash){
     return $this->cachePath.DIRECTORY_SEPARATOR.substr($uriHash, 0, 4).DIRECTORY_SEPARATOR.substr($uriHash, 4, 4);
   }
   
   
   
   public function startCache(){
      
       if( !$this->isURICacheable()) return FALSE;
       $this->cache_start_time = microtime();
       
       if(!$this->returnCachePage()){
           $this->returnToClient = TRUE; //enviar el buffer al navegador
       }
       //regitramos _shutdown_callback para gestionar el guardado de la cache despues de acabar el renderizado 
       register_shutdown_function(array($this,'_shutdown_callback'));

       ob_start(); 
       
       
   }
   
   
   public function checkRegExp ($uri=FALSE){
       if(!$uri) return FALSE;
       $this->lastPattern = FALSE;  
       $uri = preg_replace('/#.*$/', '', $uri);
       $cacheable = false;
       if (!$this->cachePages['default'] && !empty($this->cachePages['pageexpr'])){
           foreach ($this->cachePages['pageexpr'] as $patron => $details){
               if (preg_match($patron, $uri)){
                   $this->lastPattern = $patron;
                   $cacheable = true;  
                   break;
               }
               
           }
       }else if($this->cachePages['default']) {
                  $cacheable = true; 
                  $this->lastPattern = 'default';
       }
       
       if (!empty($this->rejectPages['pageexpr'])){
          foreach ($this->rejectPages['pageexpr'] as $reject_patron){
               if (preg_match($reject_patron, $uri)){
                   $cacheable = FALSE; 
                   $this->lastPattern = $reject_patron;
                   break;
               }
               
           }
       }
       return $cacheable;
             
   }
   
     
   /**
    * comprobamos si la uri es cacheable 
    * y cargamos las estructuras de cache
    */
    private function isURICacheable(){
       if($_SERVER["REQUEST_METHOD"] == 'POST') return FALSE;
       if(!$this->cacheEnabled) return FALSE;
       
       if($this->isCacheable !== NULL) return $this->isCacheable;
            
       if($this->checkRegExp($_SERVER['REQUEST_URI']) == true){
                     $rcm = new RequestCacheMeta;
                     $meta = new MyCacheMeta;
                     
                     $details = $this->cachePages['pageexpr'][$this->lastPattern];
                     
                     $rcm->cacheType = ($details['cacheType'])?: $this->default_type;
                     $rcm->patron     = $this->lastPattern;
                     $rcm->uri        = $uri;
                     
                     $meta->uri = $uri;
                     $meta->version = ($details['version'])?: 0;
                     
                     //creamos el hash para el uri
                     $uriHash = md5($_SERVER['SERVER_NAME']."{$uri}:{$this->version_general}:{$meta->version}".$this->cache_get_cookies_values());
                     $cache_path = $this->getFileCachePath($uriHash).DIRECTORY_SEPARATOR;
                     
                     $cache_filename = $this->file_prefix . $uriHash . '.html';
                     $meta_file = $file_prefix . $uriHash . '.meta';
             
                     $rcm->cache_file = $cache_path . $cache_filename;
                     $rcm->meta_pathname = $cache_path . $meta_file;
                     //$rcm->semaphoreID   = $this->getSemID($rcm->meta_pathname);
                     
                     
                     $meta = ( unserialize(@file_get_contents($rcm->meta_pathname)) )?: $meta;
                     
                     
                     $this->requestMeta  = $rcm;
                     $this->cacheMeta    = $meta;
                     $this->isCacheable = TRUE;
                     
       }else {
            $this->isCacheable = FALSE;
       }
       return $this->isCacheable;
   }
   
   
  
   
   
   
   
   /**
    * Devolvemos al cliente la pagina en cache 
    * el script muere si la cache está actualizada
    * @return boolean  true || FALSE , 
    */
   private function returnCachePage(){
       //comprobamos si la pagina puede ser cacheada
       // si la pagina no es cacheable no continuamos
       
       
       if(!($mtime = @filemtime($this->requestMeta->meta_pathname)) ) {
           //no existe el fichero no no se puede abrir
           return FALSE;
       }
       if ( !($content_size = @filesize($this->requestMeta->cache_file)) > 0) return FALSE;
       if ($mtime + $this->cache_max_time > time()) { 
            //la cache no ha caducado  
           //imprimimos y finalizamos
            $this->imprimePaginaCache(TRUE);
            
       }else if($this->requestMeta->cacheType == 'disponible'){
            //la cache ha caducado pero la cache para la uri es del tipo disponible
            //mostramos al cliente la pagina en cache pero seguimos para actualizar la cache
          
           
            if($this->cacheMeta->status != ACTUALIZANDO_CACHE ) {
                //marcamos que se está actualizando la cache.
                //imprmimomos sin finalizar
                $this->imprimePaginaCache(FALSE);
                $this->cacheMeta->status = ACTUALIZANDO_CACHE;
                @file_put_contents($this->requestMeta->meta_pathname, serialize($this->cacheMeta),LOCK_EX);
                return TRUE;
            }else {
                //la cache ya se esta actulaizando en otro proceso. Imprimimos y finalizamos
                $this->imprimePaginaCache(TRUE);
            }
        }
	
        //debemos iniciar la cache de la página
         return FALSE;
   }
   
   
   private function imprimePaginaCache($finish=TRUE){
    
       //cabeceras
       if(!empty($this->cacheMeta->headers)) foreach ($this->cacheMeta->headers as $header) {
                //seteamos los headers.
		header($header);
       }
       
       
       if ($this->cacheMeta->dynamic) {
	include($this->requestMeta->cache_file);
       } else {
       if(!@readfile ($this->requestMeta->cache_file)) 
	  return;
       }
       $log = '<!-- Cached page served by MyCache in '. $this->cache_microtime_diff($this->cache_start_time, microtime()) .' -->';
       echo $log;
       flush();
       if($finish) {
           $this->finish_inmediate = true; //por si esta registrado _shutdown_callback
           exit();
       }
   }
   
   
   
    private function _cache_ob_callback() {
               
        # Checking if last error is a fatal error 
        if(($error['type'] === E_ERROR) || ($error['type'] === E_USER_ERROR))
        {
            # Here we handle the error, displaying HTML, logging, ...
            echo 'Sorry, a serious error has occured in ' . $error['file'];
        }
        
        
        
        $buffer = ob_get_contents();
        $duration = $this->cache_microtime_diff($this->cache_start_time, microtime());
	$duration = sprintf("%0.3f", $duration);
	$buffer .= "\n<!-- Dynamic Page Served (once) in $duration seconds -->\n";

        if(!file_exists(dirname($this->requestMeta->cache_file)))
                mkdir(dirname($this->requestMeta->cache_file), 0777, true);
	
                
	if (preg_match('/<!--mclude|<!--mfunc/', $buffer)) { //Dynamic content
            $store = preg_replace('|<!--mclude (.*?)-->(.*?)<!--/mclude-->|is', 
		        "<!--mclude-->\n<?php include_once('" . ABSPATHCACHE . "$1'); ?>\n<!--/mclude-->", $buffer);
	    $store = preg_replace('|<!--mfunc (.*?)-->(.*?)<!--/mfunc-->|is', 
			"<!--mfunc-->\n<?php $1 ;?>\n<!--/mfunc-->", $store);
            $this->cacheMeta->dynamic = true;
            //* Clean function calls in tag
            $buffer = preg_replace('|<!--mclude (.*?)-->|is', '<!--mclude-->', $buffer);
            $buffer = preg_replace('|<!--mfunc (.*?)-->|is', '<!--mfunc-->', $buffer);
            
            file_put_contents($this->requestMeta->cache_file, $store,LOCK_EX);
	} else {
            file_put_contents($this->requestMeta->cache_file,$buffer,LOCK_EX);
	}
		
	
	if ( $this->returnToClient){
            ob_end_flush(); //se envia el buffer al navegador
        }else
            ob_end_clean ();
       
    }    

   
   
   public function _shutdown_callback() {
       /**
         * Comprobamos si se ha producido un error
         *  
         */
      
        # Getting last error
        if ($this->finish_inmediate){
            ob_end_flush();
            exit();
        }
        if (!$this->handlingErrorsInShutdown()){
            ob_end_flush();
            exit();
        }
	$this->_cache_ob_callback();
        $this->cacheMeta->status = CACHE_ACTUALIZADA;
	
	$response = $this->cache_get_response_headers();
	$this->cacheMeta->headers = array();
	foreach ($this->known_headers as $key) {
		if(isset($response{$key})) {
			array_push($this->cacheMeta->headers, "$key: " . $response{$key});
		}
	}
	
	if (!$response{'Last-Modified'}) {
		$value = gmdate('D, d M Y H:i:s') . ' GMT';
		/* Dont send this the first time */
		/* @header('Last-Modified: ' . $value); */
		array_push($this->cacheMeta->headers, "Last-Modified: $value");
	}
	if (!$response{'Content-Type'} && !$response{'Content-type'}) {
		$value =  "text/html; charset= UTF-8"; 
		@header("Content-Type: $value");
		array_push($this->cacheMeta->headers, "Content-Type: $value");
	}

	
	/*$semaphore = sem_get($this->requestMeta->semaphoreID, $this->maxAcquire, $this->permissions, $this->autoRelease);
        
        sem_acquire($semaphore);*/
        $serial = serialize($this->cacheMeta);
	if(!file_exists(dirname($this->requestMeta->meta_pathname)))
            mkdir(dirname($this->requestMeta->meta_pathname), 0777, true);
        file_put_contents($this->requestMeta->meta_pathname, $serial, LOCK_EX);
	
	//sem_release($semaphore);
	
  }

   /**
    * 
    * Herramientas de cache para el código del desarrollador
    * 
    */


    public function cacheGet($key,$version,$timeout=3600){
       $path = $this->getCodeCachePath($key, $version);
       
       if(!($mtime = @filemtime($path)) ) return FALSE; ////no existe el fichero no no se puede abrir
       if ($mtime + $timeout < time()) return FALSE; //ha caducado
       
       return  unserialize(file_get_contents($path));
    }


    public function cachePut($key,$version,$data){
         $path = $this->getCodeCachePath($key, $version);
         $data = serialize($data);
         if(!file_exists(dirname($path)))
                mkdir(dirname($path), 0777, true);
         file_put_contents($path, $data,LOCK_EX);
        
    }
  
    private function getCodeCachePath($key,$version){
      $uriHash = md5("$key:$version");
      $dir =  $this->cachePath.DIRECTORY_SEPARATOR.'phtml'.DIRECTORY_SEPARATOR.substr($uriHash, 4, 4);
      return $dir.DIRECTORY_SEPARATOR.$uriHash.'.phtml';
   }

   /*
    * 
    * HELPERS
    * 
    */
      
   private  function cache_microtime_diff($a, $b) {
	list($a_dec, $a_sec) = explode(' ', $a);
	list($b_dec, $b_sec) = explode(' ', $b);
	return $b_sec - $a_sec + $b_dec - $a_dec;
    }
    
    private function getSemID ($str){
        $hash = md5($str);
        $hash = substr($hash, 0, 15); // ok on 64bit systems
        $number = (int) hexdec($hash); // cap to PHP_INT_MAX anyway
        return $number;
    }
    
     
    /**
     * Obtiene las cookies propias de la cache
     * @return string
     */
    private function cache_get_cookies_values() {
	$string = '';
	while ($key = key($_COOKIE)) {
            foreach ($this->cookiesexpt as $patron) {
		if (preg_match($patron, $key)) {
			$string .= $_COOKIE[$key] . ",";
		}
            }
            next($_COOKIE);
	}
	reset($_COOKIE);
        
	return $string;
   }
   
   /**
    * Obtine los headers 
    * @return array
    */
   private function cache_get_response_headers() {
	if(function_exists('apache_response_headers')) {
		$headers = apache_response_headers();
	} else if(function_exists('headers_list')) {
		$headers = array();
		foreach(headers_list() as $hdr) {
			list($header_name, $header_value) = explode(': ', $hdr, 2);
			$headers[$header_name] = $header_value;
		}
	} else
		$headers = null;

	return $headers;
    }
    
    /**
     * comprobamos los errores producidos durante el script para checkear si
     * la podemos cachear
     */
    private function  handlingErrorsInShutdown (){
       # Getting last error
        $error = error_get_last();
        
       # Checking if last error is a fatal error 
        foreach ($this->noCache_errors as $tipoError) {
            if(($error['type'] === $tipoError)){
                # Here we handle the error, displaying HTML, logging, ...
                echo 'Sorry, a serious error has occured in ' . $error['file'];
                return FALSE;
            }
        }
      
        return TRUE;
    }
 
    
}
