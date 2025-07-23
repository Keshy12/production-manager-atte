<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository -> getUserById($_SESSION["userid"]);
$userInfo = $user -> getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$subMagazineId = $userInfo["sub_magazine_id"];
$lastId = $_POST["lastId"] ?? "";
$lastIdRange = $_POST["lastIdRange"] ?? ""; // New parameter for ID range

$lastProduction = $MsaDB -> query("
SELECT i.id, u.login, l.name, i.quantity, i.timestamp, i.comment 
FROM `inventory__{$deviceType}` i JOIN user u on i.user_id = u.user_id 
JOIN list__{$deviceType} l on i.{$deviceType}_id = l.id WHERE l.id = '$deviceId' 
AND i.sub_magazine_id = '{$subMagazineId}' AND input_type_id = 4 ORDER BY i.`id` DESC LIMIT 10;");

// Parse lastIdRange if provided (format: "firstId-lastId" or just single ID)
$highlightIds = [];
if (!empty($lastIdRange)) {
    if (strpos($lastIdRange, '-') !== false) {
        // Range format: "123-127"
        $rangeParts = explode('-', $lastIdRange);
        if (count($rangeParts) == 2) {
            $firstId = (int)$rangeParts[0];
            $lastRangeId = (int)$rangeParts[1];
            $highlightIds = range(min($firstId, $lastRangeId), max($firstId, $lastRangeId));
        }
    } else {
        // Single ID format: "123"
        $highlightIds = [(int)$lastIdRange];
    }
} elseif (!empty($lastId)) {
    // Fallback to old single ID parameter
    $highlightIds = [(int)$lastId];
}
?>
<table id="lastProductionTable" class="table mt-4 table-striped w-75"
       data-last-id-range="<?= htmlspecialchars($lastIdRange) ?>"
       data-highlight-ids="<?= htmlspecialchars(json_encode($highlightIds)) ?>">
    <thead class="thead-light">
    <tr>
        <th scope="col">ID</th>
        <th scope="col">Użytkownik</th>
        <th scope="col">Urządzenie</th>
        <th scope="col">Ilość</th>
        <th scope="col">Data</th>
        <th scope="col">Komentarz</th>
        <th scope="col" class="text-right">
            <button id="rollbackBtn" class="btn btn-secondary btn-sm" type="button" onclick="rollbackLastProduction()" disabled>
                Cofnij ostatnią
            </button>
        </th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($lastProduction as $row) {
        $currentId = (int)$row[0];
        $isHighlighted = in_array($currentId, $highlightIds);
        ?>
        <tr class="<?= $isHighlighted ? "table-info highlighted-row" : "" ; ?>" data-row-id="<?= $currentId ?>">
            <td><?=$currentId?></td>
            <td><?=$row[1]?></td>
            <td><?=$row[2]?></td>
            <td><?=$row[3]+0?></td>
            <td><?=$row[4]?></td>
            <td><?=$row[5]?></td>
            <td></td>
        </tr>
    <?php } ?>
    </tbody>
</table>