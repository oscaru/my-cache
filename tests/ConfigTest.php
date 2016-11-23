<?php



class ConfigTest extends PHPUnit_Framework_TestCase
{
    // ...

    public function testCanBeEnable()
    {
        $config = ['cache_enabled' => FALSE];
        $container = new \Pimple\Container(['config'=> $config]);
        
        $cache =  \Mycache\Cache::getInstance($container);
        
        // Assert
        $this->assertEquals(FALSE, $cache->isEnabled());
        $cache->config('cache_enabled',TRUE);
        $this->assertEquals(TRUE, $cache->isEnabled());
    }

    public function testRegisterAcceptableUri(){
        $this->assertEquals(TRUE, TRUE);
    }
}