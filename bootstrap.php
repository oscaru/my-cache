<?php

require __DIR__.'/vendor/autoload.php';


$container = new \Pimple\Container(['request'=>$request]);

$container['config'] = include __DIR__.'/config.php' ;
$container['request'] = Zend\Diactoros\ServerRequestFactory::fromGlobals();






