<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository->getUserById($_SESSION["userid"]);
$userInfo = $user->getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$subMagazineId = $userInfo["sub_magazine_id"];
$transferGroupId = $_POST["transferGroupId"] ?? ""; // New parameter for highlighting
$showCancelled = isset($_POST["showCancelled"]) && $_POST["showCancelled"] == '1';
$noGrouping = isset($_POST["noGrouping"]) && $_POST["noGrouping"] == '1';

$cancelledCondition = $showCancelled ? "" : "AND i.is_cancelled = 0";

$lastProduction = $MsaDB->query("
SELECT i.id, u.login, l.name, i.qty, i.timestamp, i.comment, i.transfer_group_id,
       i.commission_id, tg.notes as transfer_notes, tg.created_at as transfer_created_at,
       i.is_cancelled
FROM `inventory__{$deviceType}` i
LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
LEFT JOIN user u ON tg.created_by = u.user_id
JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
WHERE l.id = '$deviceId'
AND i.sub_magazine_id = '{$subMagazineId}'
AND i.input_type_id = 4
{$cancelledCondition}
ORDER BY i.transfer_group_id DESC, i.`id` DESC LIMIT 50;");

// Group entries by transfer_group_id (or treat each as individual if noGrouping)
$groupedProduction = [];
if ($noGrouping) {
    // Don't group - each entry is its own "group"
    foreach ($lastProduction as $row) {
        $groupId = 'no_group_' . $row['id'];
        $groupedProduction[$groupId] = [
            'entries' => [$row],
            'login' => $row['login'],
            'timestamp' => $row['timestamp'],
            'notes' => '',
            'total_qty' => $row['qty'],
            'cancelled_count' => $row['is_cancelled'] ? 1 : 0,
            'total_count' => 1,
            'has_cancelled' => (bool)$row['is_cancelled'],
            'all_cancelled' => (bool)$row['is_cancelled']
        ];
    }
} else {
    // Group by transfer_group_id
    foreach ($lastProduction as $row) {
        $groupId = $row['transfer_group_id'] ?: 'no_group_' . $row['id'];
        if (!isset($groupedProduction[$groupId])) {
            $groupedProduction[$groupId] = [
                'entries' => [],
                'login' => $row['login'],
                'timestamp' => $row['transfer_created_at'] ?? $row['timestamp'],
                'notes' => $row['transfer_notes'] ?? '',
                'total_qty' => 0,
                'cancelled_count' => 0,
                'total_count' => 0
            ];
        }
        $groupedProduction[$groupId]['entries'][] = $row;
        $groupedProduction[$groupId]['total_qty'] += $row['qty'];
        $groupedProduction[$groupId]['total_count']++;
        if ($row['is_cancelled']) {
            $groupedProduction[$groupId]['cancelled_count']++;
        }
    }

    // Calculate cancellation status for each group
    foreach ($groupedProduction as &$groupData) {
        $groupData['has_cancelled'] = $groupData['cancelled_count'] > 0;
        $groupData['all_cancelled'] = $groupData['cancelled_count'] === $groupData['total_count'];
    }
    unset($groupData);
}
?>
<style>
    .group-row {
        cursor: pointer;
        font-weight: bold;
        background-color: #f8f9fa !important;
    }
    .group-row:hover {
        background-color: #e9ecef !important;
    }
    .group-row .toggle-icon {
        transition: transform 0.2s;
        display: inline-block;
        font-size: 14px;
    }
    .group-row[aria-expanded="true"] .toggle-icon {
        transform: rotate(90deg);
    }
    .indent-cell {
        padding-left: 30px !important;
    }
    .group-checkbox, .row-checkbox {
        cursor: pointer;
        width: 18px;
        height: 18px;
    }
    .badge-count {
        font-size: 0.85em;
        vertical-align: middle;
    }
    .badge-cancelled-partial {
        font-size: 0.75em;
        vertical-align: middle;
    }
    .cancelled-row {
        background-color: #f8d7da !important;
        opacity: 0.8;
    }
    .cancelled-group {
        background-color: #f5c6cb !important;
        opacity: 0.85;
    }
</style>

<table id="lastProductionTable" class="table mt-4 table-striped w-75"
       data-transfer-group-id="<?= htmlspecialchars($transferGroupId) ?>"
       data-show-cancelled="<?= $showCancelled ? '1' : '0' ?>"
       data-no-grouping="<?= $noGrouping ? '1' : '0' ?>">
    <thead class="thead-light">
    <tr>
        <th scope="col" style="width: 30px;"></th>
        <th scope="col" style="width: 30px;"></th>
        <th scope="col">Użytkownik</th>
        <th scope="col">Zlecenie</th>
        <th scope="col">Urządzenie</th>
        <th scope="col">Ilość</th>
        <th scope="col">Data</th>
        <th scope="col">Komentarz</th>
        <th scope="col" class="text-right">
            <div class="d-flex justify-content-end align-items-center">
                <div class="form-check mr-3 mb-0">
                    <input type="checkbox" class="form-check-input" id="showCancelledCheckbox" <?= $showCancelled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showCancelledCheckbox" style="font-size: 0.875rem; font-weight: normal;">
                        Pokaż anulowane
                    </label>
                </div>
                <div class="form-check mr-3 mb-0">
                    <input type="checkbox" class="form-check-input" id="noGroupingCheckbox" <?= $noGrouping ? 'checked' : '' ?>>
                    <label class="form-check-label" for="noGroupingCheckbox" style="font-size: 0.875rem; font-weight: normal;">
                        Nie grupuj
                    </label>
                </div>
                <button id="rollbackBtn" class="btn btn-secondary btn-sm" type="button" onclick="rollbackLastProduction()" disabled>
                    Cofnij zaznaczone
                </button>
            </div>
        </th>
    </tr>
    </thead>
    <tbody>
    <?php
    $groupIndex = 0;
    foreach($groupedProduction as $groupId => $groupData) {
        $isNoGroup = str_starts_with($groupId, 'no_group_');
        $realGroupId = $isNoGroup ? null : $groupId;
        $entryCount = count($groupData['entries']);
        $groupIndex++;
        $collapseClass = "collapse-group-{$groupIndex}";

        // If noGrouping is enabled, render as simple rows without group headers
        if ($noGrouping) {
            $row = $groupData['entries'][0];
            $currentId = (int)$row['id'];
            $commissionInfo = $row['commission_id'] ? "Zlecenie #{$row['commission_id']}" : "Bez zlecenia";
            $isCancelled = (bool)$row['is_cancelled'];
            $transferGroupInfo = $row['transfer_group_id'] ? "Grupa #" . $row['transfer_group_id'] : "-";
            ?>
            <tr class="<?= $isCancelled ? 'cancelled-row' : '' ?>"
                data-row-id="<?= $currentId ?>"
                data-transfer-group-id="<?= $row['transfer_group_id'] ?>"
                data-is-cancelled="<?= $isCancelled ? '1' : '0' ?>">
                <td></td>
                <td>
                    <?php if (!$isCancelled): ?>
                    <input type="checkbox" class="row-checkbox"
                           data-row-id="<?= $currentId ?>"
                           data-group-index="<?= $groupIndex ?>"
                           data-transfer-group-id="<?= $row['transfer_group_id'] ?>">
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['login'] ?? '') ?></td>
                <td><?= htmlspecialchars($commissionInfo) ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= $row['qty'] > 0 ? '+' : '' ?><?= $row['qty']+0 ?></td>
                <td><?= htmlspecialchars($row['timestamp']) ?></td>
                <td><small><?= htmlspecialchars($row['comment']) ?></small></td>
                <td><small class="text-muted"><?= $transferGroupInfo ?></small></td>
            </tr>
            <?php
        } else {
            // Grouped mode - show group headers and collapsible rows
            ?>
            <!-- Group Header Row -->
            <tr class="group-row <?= $groupData['all_cancelled'] ? 'cancelled-group' : '' ?>"
                data-toggle="collapse"
                data-target=".<?= $collapseClass ?>"
                aria-expanded="false"
                aria-controls="<?= $collapseClass ?>"
                data-group-id="<?= $realGroupId ?>"
                data-group-index="<?= $groupIndex ?>"
                data-is-cancelled="<?= $groupData['all_cancelled'] ? '1' : '0' ?>">
                <td>
                    <i class="bi bi-chevron-right toggle-icon"></i>
                </td>
                <td>
                    <?php if ($realGroupId && !$groupData['has_cancelled']): ?>
                    <input type="checkbox" class="group-checkbox"
                           data-group-id="<?= $realGroupId ?>"
                           data-group-index="<?= $groupIndex ?>"
                           onclick="event.stopPropagation();">
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($groupData['login'] ?? 'Brak') ?></td>
                <td colspan="2">
                    <?php if ($realGroupId): ?>
                        <strong>Grupa transferowa #<?= $realGroupId ?></strong>
                        <span class="badge badge-secondary badge-count ml-2">
                            <?= $entryCount ?>
                            <?php
                            if ($entryCount == 1) {
                                echo 'transfer';
                            } elseif ($entryCount >= 2 && $entryCount <= 4) {
                                echo 'transfery';
                            } else {
                                echo 'transferów';
                            }
                            ?>
                        </span>
                        <?php if ($groupData['has_cancelled'] && !$groupData['all_cancelled']): ?>
                            <span class="badge badge-warning badge-cancelled-partial ml-1">
                                <?= $groupData['cancelled_count'] ?> anulowanych
                            </span>
                        <?php endif; ?>
                        <?php if ($groupData['notes']): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($groupData['notes']) ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong>Pojedynczy wpis</strong>
                    <?php endif; ?>
                </td>
                <td><strong><?= $groupData['total_qty'] > 0 ? '+' : '' ?><?= $groupData['total_qty'] ?></strong></td>
                <td><?= htmlspecialchars($groupData['timestamp']) ?></td>
                <td></td>
                <td></td>
            </tr>

            <!-- Detail Rows (Collapsible) -->
            <?php foreach($groupData['entries'] as $idx => $row) {
                $currentId = (int)$row['id'];
                $commissionInfo = $row['commission_id'] ? "Zlecenie #{$row['commission_id']}" : "Bez zlecenia";
                $isCancelled = (bool)$row['is_cancelled'];
            ?>
            <tr class="collapse <?= $collapseClass ?> <?= $isCancelled ? 'cancelled-row' : '' ?>"
                data-group-index="<?= $groupIndex ?>"
                data-row-id="<?= $currentId ?>"
                data-transfer-group-id="<?= $realGroupId ?>"
                data-is-cancelled="<?= $isCancelled ? '1' : '0' ?>">
                <td></td>
                <td>
                    <?php if (!$isCancelled): ?>
                    <input type="checkbox" class="row-checkbox"
                           data-row-id="<?= $currentId ?>"
                           data-group-index="<?= $groupIndex ?>"
                           data-transfer-group-id="<?= $realGroupId ?>">
                    <?php endif; ?>
                </td>
                <td class="indent-cell"><?= htmlspecialchars($row['login'] ?? '') ?></td>
                <td><?= htmlspecialchars($commissionInfo) ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= $row['qty'] > 0 ? '+' : '' ?><?= $row['qty']+0 ?></td>
                <td><?= htmlspecialchars($row['timestamp']) ?></td>
                <td><small><?= htmlspecialchars($row['comment']) ?></small></td>
                <td></td>
            </tr>
            <?php } ?>
        <?php } ?>
    <?php } ?>
    </tbody>
</table>

<script>
$(document).ready(function() {
    // Handle show cancelled checkbox
    $('#showCancelledCheckbox').change(function() {
        var deviceId = $('#list__device').val();
        if (!deviceId) {
            return;
        }

        var showCancelled = $(this).is(':checked') ? '1' : '0';
        var noGrouping = $('#noGroupingCheckbox').is(':checked') ? '1' : '0';

        // Reload the table with the new parameter
        $("#lastProduction").load('../public_html/components/production/last-production-table.php', {
            deviceType: DEVICE_TYPE,
            deviceId: deviceId,
            showCancelled: showCancelled,
            noGrouping: noGrouping
        }, function() {
            updateRollbackButtonState();
        });
    });

    // Handle no grouping checkbox
    $('#noGroupingCheckbox').change(function() {
        var deviceId = $('#list__device').val();
        if (!deviceId) {
            return;
        }

        var showCancelled = $('#showCancelledCheckbox').is(':checked') ? '1' : '0';
        var noGrouping = $(this).is(':checked') ? '1' : '0';

        // Reload the table with the new parameter
        $("#lastProduction").load('../public_html/components/production/last-production-table.php', {
            deviceType: DEVICE_TYPE,
            deviceId: deviceId,
            showCancelled: showCancelled,
            noGrouping: noGrouping
        }, function() {
            updateRollbackButtonState();
        });
    });

    // Update aria-expanded attribute and icon when collapse state changes
    $('.group-row').on('click', function(e) {
        // Don't toggle if clicking on checkbox
        if ($(e.target).is('.group-checkbox') || $(e.target).closest('.group-checkbox').length) {
            return;
        }

        var $this = $(this);
        var target = $this.data('target');

        // Toggle aria-expanded
        var isExpanded = $this.attr('aria-expanded') === 'true';
        $this.attr('aria-expanded', !isExpanded);
    });

    // Handle group checkbox
    $('.group-checkbox').change(function(e) {
        e.stopPropagation();
        var groupIndex = $(this).data('group-index');
        var isChecked = $(this).prop('checked');

        // Check/uncheck all child rows
        $('.row-checkbox[data-group-index="' + groupIndex + '"]').prop('checked', isChecked);

        updateRollbackButtonState();
    });

    // Handle individual row checkbox
    $('.row-checkbox').change(function() {
        var groupIndex = $(this).data('group-index');
        var groupCheckbox = $('.group-checkbox[data-group-index="' + groupIndex + '"]');

        // Check if all rows in group are checked
        var totalRows = $('.row-checkbox[data-group-index="' + groupIndex + '"]').length;
        var checkedRows = $('.row-checkbox[data-group-index="' + groupIndex + '"]:checked').length;

        if (checkedRows === totalRows && totalRows > 0) {
            groupCheckbox.prop('checked', true);
            groupCheckbox.prop('indeterminate', false);
        } else if (checkedRows > 0) {
            groupCheckbox.prop('indeterminate', true);
        } else {
            groupCheckbox.prop('checked', false);
            groupCheckbox.prop('indeterminate', false);
        }

        updateRollbackButtonState();
    });
});
</script>