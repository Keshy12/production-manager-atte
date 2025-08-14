<?php

$MsaDB = Atte\DB\MsaDB::getInstance();

$_SESSION['info'] = 'Dane nieprawidłowe. Spróbuj ponownie.';
$userName = $_POST["userName"] ?? "";
$userPassword = hash('sha256',$_POST["userPassword"]) ?? "";

$user = $MsaDB -> query("SELECT user_id, isAdmin FROM user 
WHERE login = '$userName' AND password = '$userPassword' AND isActive = 1");

$location = "http://".BASEURL."/login";

//If credentials are correct
if(!empty($user)){
    unset($_SESSION['info']);
    list($id, $isAdmin) = $user[0];

    $_SESSION["userid"] = $id;
    $_SESSION['isAdmin'] = (bool)$isAdmin;

    $location = "http://".BASEURL;

    //Redirect to previously accessed page.
    if(isset($_COOKIE["redirect"])) {
        $location = $_COOKIE["redirect"];
        setcookie("redirect", "", (-1)*time()+3600, '/');
    }
}

header("Location: ".$location);

