<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$deviceTypes = empty($_POST["deviceType"]) ? ["sku", "tht", "smd", "parts"] : [$_POST["deviceType"]];

$userIds = $MsaDB -> query("SELECT user_id 
                            FROM user 
                            ORDER BY user_id ASC", 
                            \PDO::FETCH_COLUMN);
                            
$userNames = $MsaDB -> query("SELECT name, surname 
                              FROM user 
                              ORDER BY user_id ASC", 
                              \PDO::FETCH_ASSOC);
                              
$users = array_combine($userIds, $userNames);

$inputTypes = $MsaDB -> readIdName("inventory__input_type");

$result = [];

//bg colors for card header
$bg = [
    3 => "#ffd4d1", 
    5 => "#fff3d1"
]; 


foreach($deviceTypes as $deviceType) {
    $list__device = $MsaDB -> readIdName("list__{$deviceType}", "id", "name", "WHERE isActive = 1");
    $list__device_desc = $MsaDB -> readIdName("list__{$deviceType}", "id", "description", "WHERE isActive = 1");
    $toVerify = $MsaDB -> query("SELECT id, 
                                        {$deviceType}_id, 
                                        user_id, 
                                        quantity, 
                                        commission_id, 
                                        comment, 
                                        input_type_id, 
                                        timestamp 
                                    FROM `inventory__{$deviceType}` 
                                    WHERE isVerified = 0 
                                    ORDER BY id DESC;");
    foreach($toVerify as $row) {
        list($id, $deviceId, $userId, $quantity, 
        $commissionId, $comment, $inputTypeId, $timestamp) = $row;
        $push = ["id" => $id,
                "deviceId" => $deviceId,
                "deviceName" => $list__device[$deviceId],        
                "deviceDescription" => $list__device_desc[$deviceId],  
                "userId" => $userId,
                "user" => $users[$userId]["name"]." ".$users[$userId]["surname"],
                "quantity" => $quantity+0,
                "commissionId" => $commissionId,
                "comment" => $comment,
                "backgroundColor" => $bg[$inputTypeId],
                "inputType" => $inputTypes[$inputTypeId],
                "deviceType" => $deviceType,
                "timestamp" => $timestamp    
        ];
        $result[$deviceType][] = $push;
    }
}
echo json_encode($result);


