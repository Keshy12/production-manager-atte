<?php
define('ROOT_DIRECTORY', isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT']) 
    ? $_SERVER['DOCUMENT_ROOT'].'/atte_ms_new' 
    : str_replace('\\', '/', realpath(__DIR__ . '/..')));
require_once ROOT_DIRECTORY.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);
$dotenv->load();
define("BASEURL", $_ENV["BASEURL"]);

if (php_sapi_name() !== 'cli') {
    session_start();
}


function includeWithVariables($filePath, $variables = array(), $print = true)
{
    $output = NULL;
    if(file_exists($filePath)){
        // Extract the variables to a local namespace
        extract($variables);

        // Start output buffering
        ob_start();

        // Include the template file
        include $filePath;

        // End buffering and return its contents
        $output = ob_get_clean();
    }
    if ($print) {
        print $output;
    }
    return $output;
}

function asset($path)
{
    $parts = explode('?', $path);
    $cleanPath = $parts[0];
    $queryString = isset($parts[1]) ? '?' . $parts[1] . '&' : '?';
    
    $fullPath = ROOT_DIRECTORY . '/' . ltrim($cleanPath, '/');
    $version = file_exists($fullPath) ? filemtime($fullPath) : time();
    
    return "http://" . BASEURL . "/" . ltrim($cleanPath, '/') . $queryString . "v=" . $version;
}





