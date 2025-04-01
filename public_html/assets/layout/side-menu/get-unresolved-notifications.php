<?php

use Atte\DB\MsaDB;
use Atte\Utils\NotificationRepository;

$MsaDB = MsaDB::getInstance();
$notificationRepository = new NotificationRepository($MsaDB);
$unresolvedNotifications = $notificationRepository -> getUnresolvedNotifications();
$dropdownItems = [];
foreach($unresolvedNotifications as $notification) {
    $dropdownItems[] = $notification -> returnDropdownItem();
}

$result = ["count" => count($unresolvedNotifications), "dropdown" => $dropdownItems];
echo json_encode($result);