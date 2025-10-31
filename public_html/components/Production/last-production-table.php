<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository->getUserById($_SESSION["userid"]);
$userInfo = $user->getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$subMagazineId = $userInfo["sub_magazine_id"];
$transferGroupId = $_POST["transferGroupId"] ?? ""; // New parameter for highlighting

$lastProduction = $MsaDB->query("
SELECT i.id, u.login, l.name, i.qty, i.timestamp, i.comment, i.transfer_group_id
FROM `inventory__{$deviceType}` i 
LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
LEFT JOIN user u ON tg.created_by = u.user_id 
JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id 
WHERE l.id = '$deviceId' 
AND i.sub_magazine_id = '{$subMagazineId}' 
AND i.input_type_id = 4 
AND i.is_cancelled = 0
ORDER BY i.`id` DESC LIMIT 10;");

// Generate color mapping for transfer groups
$transferGroupColors = [];
$colorClasses = ['table-info', 'table-warning', 'table-success', 'table-primary', 'table-secondary'];
$colorIndex = 0;

foreach ($lastProduction as $row) {
    $groupId = $row['transfer_group_id'];
    if ($groupId && !isset($transferGroupColors[$groupId])) {
        $transferGroupColors[$groupId] = $colorClasses[$colorIndex % count($colorClasses)];
        $colorIndex++;
    }
}
?>
<table id="lastProductionTable" class="table mt-4 table-striped w-75"
       data-transfer-group-id="<?= htmlspecialchars($transferGroupId) ?>">
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
        $currentId = (int)$row['id'];
        $groupId = $row['transfer_group_id'];
        $isHighlighted = ($groupId && $groupId == $transferGroupId);
        $colorClass = $groupId ? ($transferGroupColors[$groupId] ?? '') : '';
        $highlightClass = $isHighlighted ? 'highlighted-row' : '';
        ?>
        <tr class="<?= "$colorClass $highlightClass" ?>"
            data-row-id="<?= $currentId ?>"
            data-transfer-group-id="<?= $groupId ?>">
            <td><?=$currentId?></td>
            <td><?=$row['login']?></td>
            <td><?=$row['name']?></td>
            <td><?=$row['qty']+0?></td>
            <td><?=$row['timestamp']?></td>
            <td><?=$row['comment']?></td>
            <td></td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<script>
    // Add click handler to toggle row selection
    $(document).ready(function() {
        $('#lastProductionTable tbody tr').click(function() {
            var groupId = $(this).data('transfer-group-id');
            if (groupId) {
                // Toggle all rows with same transfer_group_id
                $('tr[data-transfer-group-id="' + groupId + '"]').toggleClass('highlighted-row');
                updateRollbackButtonState();
            }
        });
    });
</script>