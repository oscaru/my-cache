<?php

namespace Mycache;

use Psr\Http\Message;

require __DIR__ . '/MetaEntity.php';
require __DIR__ . '/RequestEntity.php';

class Cache {

    const TYPE_DISPONIBLE = 1;
    const TYPE_ACTUALIZADO = 2;

    protected static $instance;
    protected $container;
    protected $fastRoute;
    protected $config = [
        'cache_path' => __DIR__,
        'cache_enabled' => TRUE,
        'cookiesexpt' => array("/^my_cache/"), //expresiones regulares para las cookies de cache
        'file_prefix' => 'wp-cache-',
        'cache_max_time' => 3600, //una hora
        'cache_compress' => TRUE,
        'default_type' => self::TYPE_DISPONIBLE,
        'default_cache' => TRUE,
        'version_general' => 0,
        'invalidate_Headers' => ['user_agent' => ['bot', 'ia_archive', 'slurp', 'crawl', 'spider']], //si contiene un header no es cacheable
        'invalidate_cookies' => ['sessionId'],
        'known_headers' => ["Last-Modified", "Expires", "Content-Type", "Content-type", "X-Pingback", "ETag", "Cache-Control", "Pragma"],
        'known_cookies' => ["category"],
        'allow_cache_methods' => ['GET'],
    ];
    protected $acceptableUris = [];
    protected $rejectedUris = [];

    /* protected  $noCache_errors = array (E_ERROR , E_COMPILE_ERROR , E_PARSE, E_USER_ERROR );

      protected $finish_inmediate = FALSE;
      protected $cache_start_time = 0;
      protected $returnToClient   = FALSE;
      protected $known_headers = array("Last-Modified", "Expires", "Content-Type", "Content-type", "X-Pingback", "ETag", "Cache-Control", "Pragma");

      protected $maxAcquire  = 1;
      protected $permissions = 0666;
      protected $autoRelease = 1;

      protected $isCacheable = NULL;

      protected $requestEntity = FALSE;  //objeto
      protected $cacheEntity   = FALSE;  //objetoMycacheEntity

      protected $request = NULL;

      public $lastPattern = ''; //ultimo patron evaluado. Para saber que patron de url desencadena la cache
     */

    protected function __construct($container) {
        $this->container = $container;
        if (!empty($container['config']))
            $this->config = array_merge($this->config, $container['config']);
    }

    public function config($name = NULL, $value = NULL) {
        if (empty($name))
            return $this->config;
        if ($value !== NULL && isset($this->config[$name]))
            $this->config[$name] = $value;
        return (isset($this->config[$name])) ? $this->config[$name] : NULL;
    }

    public function isEnabled() {
        return $this->config('cache_enabled');
    }

    public static function getInstance(\ArrayAccess $container) {
        if (empty(self::$instance))
            self::$instance = new Cache($container);
        return self::$instance;
    }

    public function registerAceptableUri($uri) {
        if (count($uri) == 3) {
            $method  = strtoupper($uri[0]);
            $pattern = $uri[1];
            $options = $uri[2];
        } else {
            $method  = strtoupper($uri[0]);
            $pattern = $uri[1];
            $options = [];
        }

        $rules = (isset($options['rules'])) ? $options['rules'] : [];
        unset($options['rules']);

        $this->acceptableUris[] = [$method, $pattern, $options, $rules];
    }

    public function registerRejectedUri($uri) {
        list($method, $pattern) = $uri;
        $this->rejectedUris[] = [$method, $pattern];
    }

    public function isRequestCacheable(\Psr\Http\Message\RequestInterface $request) {
        if (!$this->isEnabled())
            return FALSE;
        
        if(!in_array($request->getMethod(), $this->config('allow_cache_methods')))
            return FALSE;
        
        if($this->isRejectedUri($request))
            return FALSE;

        $rejecetedDispatcher = $this->getDispacher();

        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getUri());
        switch ($routeInfo[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                if (!$this->config['default_cache'])
                    return FALSE;
                return $this->config['default_type'];
                break;

            case FastRoute\Dispatcher::FOUND:
                $hash = $routeInfo[1];
                $this->checkHeadersCond($this->cacheableStore['cond'], $request);
                // ... call $handler with $vars
                break;
        }
    }

    public function isRejectedUri(\Psr\Http\Message\RequestInterface $request) {
        $rejectedUris = $this->rejectedUris;
        
        if (empty($rejectedUris))
            return false;
        $dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) use ($rejectedUris) {
            foreach ($rejectedUris as $item) {
                $r->addRoute($item[0], $item[1], "{$key}" );
            }
        });
        
        $info = $dispatcher->dispatch($request->getMethod(), $request->getUri());
        switch ($routeInfo[0]) {
            case FastRoute\Dispatcher::FOUND:
               return $routeInfo[1];;
                break;
            
            case FastRoute\Dispatcher::NOT_FOUND:
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            default:
                return FALSE;
                break;
            
        }
        
    }

    protected function checkHeadersCond($conditions, $request) {
        $cacheAble = true;
        foreach ($conditions as $hash => $cond) {
            
        }
    }

    protected function hasCoockie($name) {
        
    }

    protected function registerCacheableRequest($method, $urlPatern, $headersCond = [], $cache = true) {
        $hash = strtolower($method) . md5($urlPatern);
        $this->cacheableStore['reg'][$hash] = [$method, $urlPatern];
        $this->cacheableStore['cond'][$hash][] = [$headersCond, $cache];
    }

    protected function getRejectedDispacher() {


        $dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
            foreach ($cacheableStore['reg']as $hash => $conf) {
                list($method, $regexp) = $conf;
                $r->addRoute($method, $regexp, $hash);
            }
        });
        return $dispatcher;
    }

    /**
     * Crea un hash único para archivo de la cache
     * @param string $uri
     * @param mixed $userid
     * @param int $version
     * @return string
     */
    protected function uriHash($uri) {
        $urihash = md5($uri);
        return $urihash;
    }

    /**
     * obtiene el path de un archivo de cache.
     * @param string $uriHash   hash del archivo
     * @return string
     */
    protected function getFileCachePath($uriHash) {
        return $this->cachePath . DIRECTORY_SEPARATOR . substr($uriHash, 0, 2) . DIRECTORY_SEPARATOR . substr($uriHash, 2);
    }

    public function checkRegExp($uri = FALSE) {
        if (!$uri)
            return FALSE;
        $this->lastPattern = FALSE;
        $uri = preg_replace('/#.*$/', '', $uri);
        $cacheable = false;
        if (!$this->cachePages['default'] && !empty($this->cachePages['pageexpr'])) {
            foreach ($this->cachePages['pageexpr'] as $patron => $details) {
                if (preg_match($patron, $uri)) {
                    $this->lastPattern = $patron;
                    $cacheable = true;
                    break;
                }
            }
        } else if ($this->cachePages['default']) {
            $cacheable = true;
            $this->lastPattern = 'default';
        }

        if (!empty($this->rejectPages['pageexpr'])) {
            foreach ($this->rejectPages['pageexpr'] as $reject_patron) {
                if (preg_match($reject_patron, $uri)) {
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
    public function isURICacheable($request = null) {
        $request = (!empty($request)) ? $request : $this->request;
        if (!$request || !$request instanceof Request)
            return false;

        if ($request->getMethod() != 'GET')
            return FALSE;
        if (!$this->cacheEnabled)
            return FALSE;

        if ($this->isCacheable !== NULL)
            return $this->isCacheable;

        if ($this->checkRegExp($_SERVER['REQUEST_URI']) == true) {
            $rcm = new RequestCacheMeta;
            $meta = new MyCacheMeta;

            $details = $this->cachePages['pageexpr'][$this->lastPattern];

            $rcm->cacheType = ($details['cacheType'])? : $this->default_type;
            $rcm->patron = $this->lastPattern;
            $rcm->uri = $uri;

            $meta->uri = $uri;
            $meta->version = ($details['version'])? : 0;

            //creamos el hash para el uri
            $uriHash = md5($_SERVER['SERVER_NAME'] . "{$uri}:{$this->version_general}:{$meta->version}" . $this->cache_get_cookies_values());
            $cache_path = $this->getFileCachePath($uriHash) . DIRECTORY_SEPARATOR;

            $cache_filename = $this->file_prefix . $uriHash . '.html';
            $meta_file = $file_prefix . $uriHash . '.meta';

            $rcm->cache_file = $cache_path . $cache_filename;
            $rcm->meta_pathname = $cache_path . $meta_file;
            //$rcm->semaphoreID   = $this->getSemID($rcm->meta_pathname);


            $meta = ( unserialize(@file_get_contents($rcm->meta_pathname)) )? : $meta;


            $this->requestMeta = $rcm;
            $this->cacheMeta = $meta;
            $this->isCacheable = TRUE;
        } else {
            $this->isCacheable = FALSE;
        }
        return $this->isCacheable;
    }

    public function startCache(Request $request) {
        $this->request = $request;

        if (!$this->isURICacheable())
            return FALSE;
        $this->cache_start_time = microtime();

        if (!$this->returnCachePage()) {
            $this->returnToClient = TRUE; //enviar el buffer al navegador
        }
        //regitramos _shutdown_callback para gestionar el guardado de la cache despues de acabar el renderizado 
        register_shutdown_function(array($this, '_shutdown_callback'));

        ob_start();
    }

}
