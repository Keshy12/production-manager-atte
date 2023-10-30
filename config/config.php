<?php

require_once 'C:/xampp/htdocs/atte_ms_new/vendor/autoload.php';
require_once 'config-google-sheets.php';

define("ROOT_DIRECTORY", $_SERVER['DOCUMENT_ROOT']."/atte_ms_new");

$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);
$dotenv->load();


