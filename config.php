<?php

$config = [
    'cache_path' => __DIR__,
    'cache_enabled' => TRUE,
    'cache_compress' => true,
    'cache_acceptable_uri' => [
        '/^prue/',
        ['/^temp/', ['cache_max_time' => 40000]]
    ],
    'cache_rejected_uri' => [
       ['/',['rules' => ['hasCookie:sessionID']] ]
    ]
];


return $config;
