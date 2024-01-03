<?php

$MsaDB = Atte\DB\MsaDB::getInstance();

$_SESSION['info'] = 'Dane nieprawidłowe. Spróbuj ponownie.';
$userName = $_POST["userName"] ?? "";
$userPassword = hash('sha256',$_POST["userPassword"]) ?? "";

$user = $MsaDB -> query("SELECT user_id, isAdmin FROM user 
WHERE login = '$userName' AND password = '$userPassword'");

//If credentials are correct
if(!empty($user)){
    unset($_SESSION['info']);
    list($id, $isAdmin) = $user[0];
    $_SESSION["userid"] = $id;
    $_SESSION['isAdmin'] = (bool)$isAdmin;

    //Redirect to previously accessed page.
    if(!empty($_POST["redirect"])) {
        header("Location: ".$_POST['redirect']."");
        unset($_COOKIE["redirect"]);
        die();
    }

    header("Location: /atte_ms_new");
    die();
}

header('Location: /atte_ms_new/login');
die();


