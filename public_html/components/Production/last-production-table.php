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
       i.commission_id, tgt.template as transfer_template, tg.params as transfer_params,
       tg.created_at as transfer_created_at, i.is_cancelled
FROM `inventory__{$deviceType}` i
LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
LEFT JOIN ref__transfer_group_types tgt ON tg.type_id = tgt.id
LEFT JOIN user u ON tg.created_by = u.user_id
JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
WHERE l.id = '$deviceId'
AND i.sub_magazine_id = '{$subMagazineId}'
AND i.input_type_id = 4
{$cancelledCondition}
ORDER BY i.transfer_group_id DESC, i.`id` DESC LIMIT 50;");


// Stage 2: For grouped mode, fetch ALL device types for these groups
$allTransfers = [];
if (!$noGrouping && !empty($lastProduction)) {
    // Extract transfer group IDs from Stage 1
    $transferGroupIds = array_filter(array_unique(array_column($lastProduction, 'transfer_group_id')));

    if (!empty($transferGroupIds)) {
        $groupIdsStr = implode(',', array_map('intval', $transferGroupIds));
        $deviceTypes = ['sku', 'smd', 'tht', 'parts'];

        foreach ($deviceTypes as $type) {
            $hasBomId = in_array($type, ['sku', 'smd', 'tht']);
            $bomField = $hasBomId ? "i.{$type}_bom_id," : "";

            $query = "
                SELECT
                    i.id,
                    i.{$type}_id as device_id,
                    i.qty,
                    i.timestamp,
                    i.comment,
                    i.transfer_group_id,
                    i.commission_id,
                    i.is_cancelled,
                    {$bomField}
                    l.name as name,
                    '$type' as device_type,
                    tgt.template as transfer_template,
                    tg.params as transfer_params,
                    tg.created_at as transfer_created_at,
                    u.login
                FROM `inventory__{$type}` i
                LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
                LEFT JOIN ref__transfer_group_types tgt ON tg.type_id = tgt.id
                LEFT JOIN user u ON tg.created_by = u.user_id

                JOIN list__{$type} l ON i.{$type}_id = l.id
                WHERE i.transfer_group_id IN ($groupIdsStr)
                AND i.sub_magazine_id = '{$subMagazineId}'
                {$cancelledCondition}
                ORDER BY i.id DESC
            ";

            $transfers = $MsaDB->query($query);
            if (!empty($transfers)) {
                $allTransfers = array_merge($allTransfers, $transfers);
            }
        }
    }

    // Use allTransfers for grouped mode
    $dataToGroup = !empty($allTransfers) ? $allTransfers : $lastProduction;
} else {
    // Ungrouped mode - use original query results
    $dataToGroup = $lastProduction;
}

// Group entries by transfer_group_id (or treat each as individual if noGrouping)
$groupedProduction = [];
if ($noGrouping) {
    // Don't group - each entry is its own "group"
    foreach ($dataToGroup as $row) {
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
    foreach ($dataToGroup as $row) {
        $groupId = $row['transfer_group_id'] ?: 'no_group_' . $row['id'];
        if (!isset($groupedProduction[$groupId])) {
            $formattedNote = \Atte\Utils\TransferGroupManager::formatNote($row['transfer_template'] ?? '', $row['transfer_params'] ?? '[]');
            $groupedProduction[$groupId] = [
                'entries' => [],
                'login' => $row['login'],
                'timestamp' => $row['transfer_created_at'] ?? $row['timestamp'],
                'notes' => $formattedNote,
                'total_qty' => 0,
                'cancelled_count' => 0,
                'total_count' => 0
            ];
        }

        $groupedProduction[$groupId]['entries'][] = $row;

        // Only add quantity if this is the produced item, not a component
        $rowDeviceType = $row['device_type'] ?? $deviceType;
        $rowDeviceId = isset($row['device_id']) ? (int)$row['device_id'] : (int)$deviceId;
        $isProducedItem = ($rowDeviceType == $deviceType && $rowDeviceId == $deviceId);

        if ($isProducedItem) {
            $groupedProduction[$groupId]['total_qty'] += $row['qty'];
        }

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

    // Sort entries within each group: produced product (selectable) first, then components
    foreach ($groupedProduction as &$groupData) {
        usort($groupData['entries'], function($a, $b) use ($deviceType, $deviceId) {
            $aDeviceType = $a['device_type'] ?? $deviceType;
            $aDeviceId = (int)$a['device_id'];
            $aIsComponent = !($aDeviceType == $deviceType && $aDeviceId == $deviceId);

            $bDeviceType = $b['device_type'] ?? $deviceType;
            $bDeviceId = (int)$b['device_id'];
            $bIsComponent = !($bDeviceType == $deviceType && $bDeviceId == $deviceId);

            // Produced product (not component) comes first
            if (!$aIsComponent && $bIsComponent) return -1;
            if ($aIsComponent && !$bIsComponent) return 1;

            // If both are same type, maintain original order (by id descending)
            return (int)$b['id'] - (int)$a['id'];
        });
    }
    unset($groupData);
}

/**
 * Generate device type badge HTML
 */
function getDeviceTypeBadgeHtml($deviceType) {
    if (empty($deviceType)) return '';

    $badgeClasses = [
        'sku' => 'badge-primary',
        'tht' => 'badge-success',
        'smd' => 'badge-info',
        'parts' => 'badge-warning'
    ];

    $badgeClass = $badgeClasses[strtolower($deviceType)] ?? 'badge-secondary';
    $typeLabel = strtoupper($deviceType);

    return "<span class='badge {$badgeClass} badge-sm mr-1'>{$typeLabel}</span>";
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
    /* Style for disabled component checkboxes */
    .component-checkbox:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }
    /* Optional: Style for component rows */
    tr[data-is-component="1"] .row-checkbox {
        cursor: not-allowed;
    }
</style>

<table id="lastProductionTable" class="table mt-4 table-striped w-75"
       data-transfer-group-id="<?= htmlspecialchars($transferGroupId) ?>"
       data-show-cancelled="<?= $showCancelled ? '1' : '0' ?>"
       data-no-grouping="<?= $noGrouping ? '1' : '0' ?>">
    <thead class="thead-light">
    <tr>
        <th scope="col" style="width: 30px;">
            <?php if (!$noGrouping): ?>
                <!-- Device Type Filter (only in grouped mode) -->
                <div class="form-group mb-0 mr-3">
                    <label for="deviceTypeFilter" class="mr-2 mb-0" style="font-size: 0.875rem;">Typ urządzenia:</label>
                    <select id="deviceTypeFilter" class="form-control form-control-sm d-inline-block" style="width: auto;">
                        <option value="all" selected>Wszystkie</option>
                        <option value="sku">SKU</option>
                        <option value="smd">SMD</option>
                        <option value="tht">THT</option>
                        <option value="parts">PARTS</option>
                    </select>
                </div>
            <?php endif; ?>
        </th>
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
            <tr class="<?= $isCancelled ? 'cancelled-row' : '' ?> transfer-row"
                data-row-id="<?= $currentId ?>"
                data-transfer-group-id="<?= $row['transfer_group_id'] ?>"
                data-is-cancelled="<?= $isCancelled ? '1' : '0' ?>"
                data-device-type="<?= htmlspecialchars($row['device_type'] ?? $deviceType) ?>">
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
                <td>
                    <?= getDeviceTypeBadgeHtml($row['device_type'] ?? $deviceType) ?>
                    <?= htmlspecialchars($row['name']) ?>
                </td>
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

                // Determine if this transfer is a consumed component or produced product
                $transferDeviceType = $row['device_type'] ?? $deviceType;
                $transferDeviceId = isset($row['device_id']) ? (int)$row['device_id'] : (int)$deviceId;
                $isComponent = !($transferDeviceType == $deviceType && $transferDeviceId == $deviceId);
            ?>
            <tr class="collapse <?= $collapseClass ?> <?= $isCancelled ? 'cancelled-row' : '' ?> transfer-row"
                data-group-index="<?= $groupIndex ?>"
                data-row-id="<?= $currentId ?>"
                data-transfer-group-id="<?= $realGroupId ?>"
                data-is-cancelled="<?= $isCancelled ? '1' : '0' ?>"
                data-device-type="<?= htmlspecialchars($transferDeviceType) ?>"
                data-device-id="<?= $transferDeviceId ?>"
                data-is-component="<?= $isComponent ? '1' : '0' ?>">
                <td></td>
                <td>
                    <?php if (!$isCancelled): ?>
                    <input type="checkbox"
                           class="row-checkbox <?= $isComponent ? 'component-checkbox' : '' ?>"
                           data-row-id="<?= $currentId ?>"
                           data-group-index="<?= $groupIndex ?>"
                           data-transfer-group-id="<?= $realGroupId ?>"
                           <?= $isComponent ? 'disabled' : '' ?>>
                    <?php endif; ?>
                </td>
                <td class="indent-cell"><?= htmlspecialchars($row['login'] ?? '') ?></td>
                <td><?= htmlspecialchars($commissionInfo) ?></td>
                <td>
                    <?= getDeviceTypeBadgeHtml($row['device_type'] ?? $deviceType) ?>
                    <?= htmlspecialchars($row['name']) ?>
                </td>
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

        // Check/uncheck all child rows (including disabled component checkboxes)
        // This ensures components are selected via group action
        $('.row-checkbox[data-group-index="' + groupIndex + '"]').each(function() {
            // Enable checkbox temporarily if it's a disabled component
            var wasDisabled = $(this).prop('disabled');
            if (wasDisabled) {
                $(this).prop('disabled', false);
            }

            $(this).prop('checked', isChecked);

            // Re-disable if it was originally disabled
            if (wasDisabled) {
                $(this).prop('disabled', true);
            }
        });

        updateRollbackButtonState();
    });

    // Handle individual row checkbox
    $('.row-checkbox').change(function() {
        // Don't handle disabled checkboxes (they shouldn't fire this event, but just in case)
        if ($(this).prop('disabled')) {
            return;
        }

        var groupIndex = $(this).data('group-index');
        var groupCheckbox = $('.group-checkbox[data-group-index="' + groupIndex + '"]');

        // Count only non-disabled rows when determining group checkbox state
        var checkedRows = $('.row-checkbox[data-group-index="' + groupIndex + '"]:checked').not(':disabled').length;

        // Only show indeterminate state or unchecked - never auto-check the group checkbox
        // User must explicitly click the group checkbox to select all
        if (checkedRows > 0) {
            groupCheckbox.prop('checked', false);
            groupCheckbox.prop('indeterminate', true);
        } else {
            groupCheckbox.prop('checked', false);
            groupCheckbox.prop('indeterminate', false);
        }

        updateRollbackButtonState();
    });

    // Device Type Filter Handler
    $('#deviceTypeFilter').change(function() {
        var selectedType = $(this).val();
        filterTransfersByDeviceType(selectedType);
    });

    /**
     * Filter transfer rows by device type
     */
    function filterTransfersByDeviceType(deviceType) {
        if (deviceType === 'all') {
            $('.transfer-row').show();
        } else {
            $('.transfer-row').hide();
            $('.transfer-row[data-device-type="' + deviceType + '"]').show();
        }

        updateGroupHeaderCounts();
    }

    /**
     * Update group header counts after filtering
     */
    function updateGroupHeaderCounts() {
        $('.group-row').each(function() {
            var $groupRow = $(this);
            var groupIndex = $groupRow.data('group-index');

            // Count visible (non-cancelled) transfers
            var visibleCount = $('.transfer-row[data-group-index="' + groupIndex + '"]')
                .filter(':visible')
                .filter('[data-is-cancelled="0"]')
                .length;

            // Update badge count with proper Polish pluralization
            var $badge = $groupRow.find('.badge-count');
            if ($badge.length > 0) {
                var countText = visibleCount;
                if (visibleCount == 1) {
                    countText += ' transfer';
                } else if (visibleCount >= 2 && visibleCount <= 4) {
                    countText += ' transfery';
                } else {
                    countText += ' transferów';
                }
                $badge.html(countText);
            }

            // Calculate total visible qty
            var totalQty = 0;
            $('.transfer-row[data-group-index="' + groupIndex + '"]')
                .filter(':visible')
                .each(function() {
                    var qtyText = $(this).find('td').eq(5).text().trim();
                    var qty = parseFloat(qtyText.replace('+', ''));
                    if (!isNaN(qty)) {
                        totalQty += qty;
                    }
                });

            // Update total qty display
            var $qtyCell = $groupRow.find('td').eq(5);
            var qtyDisplay = totalQty > 0 ? '+' + totalQty : totalQty.toString();
            $qtyCell.html('<strong>' + qtyDisplay + '</strong>');
        });
    }

    // Make filtering functions globally accessible
    window.filterTransfersByDeviceType = filterTransfersByDeviceType;
});
</script>