<?php
require_once '../config/config-google-sheets.php';

header('Content-Type: application/json');
try {
    $adapter->authenticate();
    $token = $adapter->getAccessToken();
    $db = new Atte\Api\GoogleOAuth();
    $db->update_access_token(json_encode($token));
    echo 'Access token inserted successfully.';
}
catch( Exception $e ){
    echo $e->getMessage() ;
}

