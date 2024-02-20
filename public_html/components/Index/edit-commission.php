<?php

$id = $_POST["id"];
$priority = $_POST["priority"];
$receivers = $_POST["subcontractors"] ?? "";
$MsaDB = Atte\DB\MsaDB::getInstance();

$commissionRepository = new Atte\Utils\CommissionRepository($MsaDB);
$currentCommission = $commissionRepository -> getCommissionById($id);
$currentCommission -> updatePriority($priority);
if(!empty($receivers)) $currentCommission -> updateReceivers($receivers);



$colors = ["transparent", "green", "yellow", "red"];
$priority = $currentCommission -> commissionValues['priority'];

$usersName = $MsaDB -> query("SELECT name, surname FROM user ORDER BY user_id ASC", PDO::FETCH_ASSOC);
$usersId = $MsaDB -> query("SELECT user_id FROM user ORDER BY user_id ASC", PDO::FETCH_COLUMN);
$users = array_combine($usersId, $usersName);
$receivers = $currentCommission -> getReceivers();
$visibility = count($receivers) == 1 ? "hidden" : "visible";
foreach($receivers as $receiver) {
    $receiversPrint[$receiver] = $users[$receiver]['name']." ".$users[$receiver]['surname'];
}

$result = [
    'priority' => $priority,
    'color' => $colors[$priority],
    'visibility' => $visibility,
    'receiversPrint' => implode('<br>', $receiversPrint),
    'receivers' => implode(',', $receivers)

];
if(array_search($_SESSION['userid'], $receivers) === false) {
    $result['visibility'] = 'hidethis';
}
 

echo json_encode($result);