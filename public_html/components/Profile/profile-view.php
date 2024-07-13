<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\UserRepository;

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);
$userRepository = new UserRepository($MsaDB);

$user = $userRepository -> getUserById($_SESSION["userid"]);
$userInfo = $user -> getUserInfo();

$submagazine_list = $MsaDB -> readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", "ORDER BY type_id ASC");

?>

<div class="d-flex align-items-center justify-content-center mt-4">
    <div style="max-width: 700px" class="container">
        <h1 class="text-center">Profil: <?=$userInfo['login']?></h1>
        <input type="hidden" name="userid" value="<?=$userInfo['user_id']?>">
        Imię: <input class="form-control rounded ml-2 mr-2" value ="<?=$userInfo['name']?>" disabled>
        Nazwisko: <input class="form-control rounded ml-2 mr-2" value ="<?=$userInfo['surname']?>" disabled>
        Email: <input class="form-control rounded ml-2 mr-2" value ="<?=$userInfo['email']?>" disabled>
        Magazyn: <input class="form-control rounded ml-2 mr-2" value ="<?=$submagazine_list[$userInfo['sub_magazine_id']]?>" disabled>
        <hr>
        <h1 class="text-center">Zmień hasło</h1>
        <form id="passwordForm" method="post" action="http://<?=BASEURL?>/public_html/components/profile/change-password.php" class="form">
            <input type="hidden" name="user_selected" value="<?=$userInfo['user_id']?>">
            Stare hasło: <input type="password" name="old-password" class="form-control rounded ml-2 mr-2" id="pass" autocomplete="off" name="password" required>
            Nowe hasło: <input type="password" name="new-password" class="form-control rounded ml-2 mr-2" autocomplete="off" name="password" minlength="8" required>
            Powtórz hasło: <input type="password" name="confirm-new-password" class="form-control rounded ml-2 mr-2" autocomplete="off" name="password" minlength="8" required>
            <div class="text-center">
                <button class="text-center form-control btn btn-primary w-25 my-4 rounded" type="submit">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<div class="d-flex align-items-center justify-content-center">
    <div id="resultMessage" style="display: none;" class="alert alert-danger w-25 text-center mb-4"></div> 
</div>


<script src="http://<?=BASEURL?>/public_html/components/profile/profile-view.js"></script>
