<?php

require_once './../vendor/autoload.php'; // 加载自动加载文件

use ocipdo\PDO as OCIPDO;

$pdo = new OCIPDO('oci:dbname=//', 'pan', 'panpan');

var_dump($pdo);