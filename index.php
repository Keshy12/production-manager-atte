<?php
require_once 'config/config.php';

includeWithVariables('public_html/assets/layout/header.php', array('title' => 'Strona Głowna'));

$request = $_SERVER['REQUEST_URI'];
$viewDir = ROOT_DIRECTORY .'/public_html/assets/components';
var_dump($request);

var_dump($viewDir);

// switch ($request) {
//     case '':
//     case '/':
//         require __DIR__ . $viewDir . 'index.php';
//         break;

//     case '/views/users':
//         require __DIR__ . $viewDir . 'users.php';
//         break;

//     case '/contact':
//         require __DIR__ . $viewDir . 'contact.php';
//         break;

//     default:
//         http_response_code(404);
//         require __DIR__ . $viewDir . '404.php';
// }