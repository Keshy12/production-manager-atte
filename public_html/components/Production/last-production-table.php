<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository -> getUserById($_SESSION["userid"]);
$userInfo = $user -> getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$subMagazineId = $userInfo["sub_magazine_id"];

$lastProduction = $MsaDB -> query("
SELECT u.login, l.name, i.quantity, i.timestamp, i.comment 
FROM `inventory__{$deviceType}` i JOIN user u on i.user_id = u.user_id 
JOIN list__{$deviceType} l on i.{$deviceType}_id = l.id WHERE l.id = '$deviceId' 
AND i.sub_magazine_id = '{$subMagazineId}' AND input_type_id = 4 ORDER BY i.`id` DESC LIMIT 10;");

?>
<table class="table mt-4 table-striped w-75">
    <thead class="thead-light">
        <tr>
            <th scope="col">Użytkownik</th>
            <th scope="col">Urządzenie</th>
            <th scope="col">Ilość</th>
            <th scope="col">Data</th>
            <th scope="col">Komentarz</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($lastProduction as $row) { ?> 
        <tr>
            <td><?=$row[0]?></td>
            <td><?=$row[1]?></td>
            <td><?=$row[2]+0?></td>
            <td><?=$row[3]?></td>
            <td><?=$row[4]?></td>
        </tr>
        <?php } ?>
    </tbody>
</table>
