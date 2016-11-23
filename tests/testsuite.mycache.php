<?php

require __DIR__.'/../vendor/autoload.php';

require __DIR__.'/ConfigTest.php';

$container = new \Pimple\Container();


$suite = new PHPUnit_Framework_TestSuite();
$suite->addTestSuite("ConfigTest");
PHPUnit_TextUI_TestRunner::run($suite);
