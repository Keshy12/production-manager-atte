<?php
define('ROOT_DIRECTORY', $_SERVER['DOCUMENT_ROOT'].'/atte_ms_new');
require_once ROOT_DIRECTORY.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);
$dotenv->load();
define("BASEURL", $_ENV["BASEURL"]);

session_start();

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




