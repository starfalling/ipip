<?php


ini_set('memory_limit', '2G');

spl_autoload_register(function ($class) {
    if (strpos($class, 'ipip\db') !== FALSE) {
        require_once __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
    }
});


$city = new ipip\db\City(__DIR__ . '/ipipfree.ipdb');
var_dump($city->find('139.228.209.62', "CN")[0] === '印度尼西亚');
var_dump($city->find('27.56.123.5', "CN")[0] === '印度');
var_dump($city->find('89.158.179.130', "CN")[0] === '法国');
var_dump($city->find('113.212.114.46', "CN")[0] === '印度尼西亚');
var_dump($city->find('182.0.143.11', "CN")[0] === '印度尼西亚');
var_dump($city->find('104.189.119.69', "CN")[0] === '美国');


$start_time = microtime(true);
for ($i = 0; $i < 100000; $i++) {
    $ip = long2ip(rand(0, PHP_INT_MAX));
    $city->find($ip, 'CN');
}
$end_time = microtime(true);
echo $end_time - $start_time, "\n\n";