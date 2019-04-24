<?php

require_once './../vendor/autoload.php'; // 加载自动加载文件

use ocipdo\PDO as OCIPDO;

$pdo = new OCIPDO('oci:dbname=(DESCRIPTION=(ADDRESS_LIST = (ADDRESS = (PROTOCOL = TCP)(HOST = 10.207.33.86)(PORT = 1521)))(CONNECT_DATA=(SID=orcl)))', 'pan', 'panpan');

var_dump($pdo);