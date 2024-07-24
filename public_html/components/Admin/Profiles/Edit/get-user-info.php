<?php 
use Atte\DB\MsaDB;
use Atte\Utils\UserRepository;

$MsaDB = MsaDB::getInstance();
$userRepository = new UserRepository($MsaDB);

$userId = $_POST["userid"];
$user = $userRepository -> getUserById($userId);

$list__tht = $MsaDB -> readIdName("list__tht", "id", "name", "WHERE isActive = 1");
$list__tht_desc = $MsaDB -> readIdName("list__tht", "id", "description", "WHERE isActive = 1");
$list__smd = $MsaDB -> readIdName("list__smd", "id", "name", "WHERE isActive = 1");
$list__smd_desc = $MsaDB -> readIdName("list__smd", "id", "description", "WHERE isActive = 1");

$userInfo = $user -> getUserInfo();
unset($userInfo["password"], $userInfo["isAdmin"], $userInfo["parent_sub_magazine_id"], 
        $userInfo["type_id"], $userInfo["sub_magazine_name"]);

$THTUsed = $user -> getDevicesUsed("tht");
$SMDUsed = $user -> getDevicesUsed("smd");

$userTHTUsed = [];
$userSMDUsed = [];
foreach($THTUsed as $THTId)
{
    $userTHTUsed[] = [$THTId, $list__tht[$THTId], $list__tht_desc[$THTId]];
}
foreach($SMDUsed as $SMDId)
{
    $userSMDUsed[] = [$SMDId, $list__smd[$SMDId], $list__smd_desc[$SMDId]];
}

echo json_encode([$userInfo, $userTHTUsed, $userSMDUsed], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
