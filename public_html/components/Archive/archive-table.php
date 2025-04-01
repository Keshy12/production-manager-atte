<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$limit = $_POST["limit"] ?? 10;
if($limit<1) $limit = 1;
$limit++;

$offset = ($_POST["page"]-1)*$limit;
$deviceType = $_POST["device_type"];
$userIds = $_POST["user_ids"] ?? [];
$deviceIds = $_POST["device_ids"] ?? [];
$inputTypesIds = $_POST["input_type_id"] ?? [];

foreach($userIds as $key => $userId) {
    $userIds[$key] = "i.user_id = ".$userId;
}
$userIds = empty($userIds) ? [1] : $userIds;
$conditions[] = implode(" OR ", $userIds);

foreach($deviceIds as $key => $deviceId) {
    $deviceIds[$key] = "i.".$deviceType."_id = ".$deviceId;
}
$deviceIds = empty($deviceIds) ? [1] : $deviceIds;
$conditions[] = implode(" OR ", $deviceIds);

foreach($inputTypesIds as $key => $inputType) {
    $inputTypesIds[$key] = "i.input_type_id = ".$inputType;
}
$inputTypesIds = empty($inputTypesIds) ? [1] : $inputTypesIds;
$conditions[] = implode(" OR ", $inputTypesIds);

$conditions = "(".implode(") AND (", $conditions).")";


$inventory__device = $MsaDB -> query("SELECT CONCAT(u.name, ' ', u.surname), m.sub_magazine_name, l.name, CAST(i.quantity as float), i.timestamp 
                                      FROM `inventory__$deviceType` i
                                      JOIN `list__$deviceType` l
                                          ON i.{$deviceType}_id = l.id
                                      JOIN `user` u
                                          ON i.user_id = u.user_id
                                      JOIN magazine__list m 
                                          ON i.sub_magazine_id = m.sub_magazine_id
                                      WHERE $conditions 
                                      ORDER BY i.timestamp DESC 
                                      LIMIT $limit OFFSET $offset",
                                      PDO::FETCH_NUM);

$nextPageAvailable = isset($inventory__device[$limit-1]);
if($nextPageAvailable) unset($inventory__device[$limit-1]);

echo json_encode([$inventory__device, $nextPageAvailable], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);