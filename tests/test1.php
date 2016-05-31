<?php

use RedLock\RedLock;

require_once __DIR__ . '/../vendor/autoload.php';

$servers = [
    ['127.0.0.1', 6379, 0.01],
    ['127.0.0.1', 6379, 0.01],
    ['127.0.0.1', 6379, 0.01],
];

$instances = [];
foreach($servers as $i => $server) {
    $instance = new \Redis();
    list($host, $port, $timeout) = $server;
    $instance->connect($host, $port, $timeout);
    $instance->select($i);
    $instances[] = $instance;
}

$redLock = new RedLock($instances);

while (true) {
    $lock = $redLock->lock('test', 10000);

    if ($lock) {
        print_r($lock);
    } else {
        print "Lock not acquired\n";
    }
}
