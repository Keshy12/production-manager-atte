<?php
declare( strict_types = 1 );

use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;
use Atte\Utils\TransferGroupManager;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ( $action ) {
    case "get_cancellation_data":
        getCancellationData($MsaDB);
        break;
    case "get_details_data":
        getDetailsData($MsaDB);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Nieprawidłowa akcja.']);
        break;
}

function getCancellationData($MsaDB) {
    try {
        $commissionId = $_POST['commissionId'] ?? $_GET['commissionId'] ?? '';
        $isGrouped = isset($_POST['isGrouped']) && ($_POST['isGrouped'] === 'true' || $_POST['isGrouped'] === '1' || $_POST['isGrouped'] === 1);
        $filters = isset($_POST['filters']) ? json_decode($_POST['filters'], true) : [];

        $commissionRepository = new CommissionRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);

        $mainCommission = $commissionRepository->getCommissionById($commissionId);
        $mainRow = $mainCommission->commissionValues;
        $mainType = $mainCommission->deviceType;
        $mainBomId = (int)$mainRow['bom_id'];
        
        $mainReceiversList = $mainCommission->getReceivers();
        sort($mainReceiversList);
        $mainReceiversStr = implode(',', $mainReceiversList);

        $commissionIds = [];

        if ($isGrouped) {
            $statements = ["1"];
            if (isset($filters['showCancelled']) && !$filters['showCancelled']) {
                $statements[] = "cl.is_cancelled = 0";
            }
            if (!empty($filters['state_id'])) {
                $stateStatement = array();
                foreach($filters['state_id'] as $state) {
                    $stateStatement[] = "cl.state = ".$MsaDB->db->quote($state);
                }
                $statements[] = "(".implode(" OR ", $stateStatement).")";
            }
            if (!empty($filters['priority_id'])) {
                $priorityStatement = array();
                foreach($filters['priority_id'] as $priority) {
                    $priorityStatement[] = "cl.priority = ".$MsaDB->db->quote($priority);
                }
                $statements[] = "(".implode(" OR ", $priorityStatement).")";
            }
            if (!empty($filters['dateFrom'])) {
                $statements[] = "DATE(cl.created_at) >= ".$MsaDB->db->quote($filters['dateFrom']);
            }
            if (!empty($filters['dateTo'])) {
                $statements[] = "DATE(cl.created_at) <= ".$MsaDB->db->quote($filters['dateTo']);
            }

            $additionalConditions = implode(" AND ", $statements);

            $allCommissions = $MsaDB->query("
                SELECT cl.id, GROUP_CONCAT(DISTINCT cr.user_id ORDER BY cr.user_id) as receivers
                FROM commission__list cl
                JOIN commission__receivers cr ON cl.id = cr.commission_id
                WHERE cl.device_type = '$mainType'
                AND cl.bom_id = $mainBomId
                AND cl.warehouse_from_id = {$mainRow['warehouse_from_id']}
                AND cl.warehouse_to_id = {$mainRow['warehouse_to_id']}
                AND cl.state = '{$mainRow['state']}'
                AND $additionalConditions
                GROUP BY cl.id
                HAVING receivers = '$mainReceiversStr'
                ORDER BY cl.is_cancelled ASC, cl.created_at DESC
            ", PDO::FETCH_ASSOC);

            foreach ($allCommissions as $c) {
                $cId = (int)$c['id'];
                if (!empty($filters['receivers'])) {
                    $commReceivers = explode(',', $c['receivers']);
                    $hasMatchingReceiver = false;
                    foreach ($filters['receivers'] as $filteredReceiver) {
                        if (in_array($filteredReceiver, $commReceivers)) {
                            $hasMatchingReceiver = true;
                            break;
                        }
                    }
                    if ($hasMatchingReceiver) $commissionIds[] = $cId;
                } else {
                    $commissionIds[] = $cId;
                }
            }
        } else {
            $commissionIds = [(int)$commissionId];
        }

        if (empty($commissionIds)) {
            echo json_encode(['success' => true, 'clickedCommissionId' => $commissionId, 'isGrouped' => $isGrouped, 'commissionsData' => [], 'transfersByCommission' => []]);
            return;
        }

        $commissions = $commissionRepository->getCommissionsByIds($commissionIds);
        $nameLists = [
            'laminate' => $MsaDB->readIdName("list__laminate"),
            'sku' => $MsaDB->readIdName("list__sku"),
            'tht' => $MsaDB->readIdName("list__tht"),
            'smd' => $MsaDB->readIdName("list__smd"),
            'parts' => $MsaDB->readIdName("list__parts")
        ];
        $magazines = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name");

        $flatBom = $MsaDB->query("
            SELECT DISTINCT parts_id, sku_id, tht_id, smd_id, quantity
            FROM bom__flat
            WHERE bom_{$mainType}_id = $mainBomId
        ");

        $componentTypes = ['parts', 'sku', 'tht', 'smd'];
        $inventoryData = [];
        $idsStr = implode(',', $commissionIds);
        $allGroupIds = [];
        
        foreach ($componentTypes as $type) {
            $inventoryData[$type] = [];
            $rows = $MsaDB->query("SELECT * FROM inventory__{$type} WHERE commission_id IN ($idsStr) AND qty != 0 ORDER BY is_cancelled ASC, timestamp ASC");
            foreach ($rows as $row) {
                $compId = $row[$type.'_id'];
                $inventoryData[$type][$row['commission_id']][$compId][] = $row;
                if ($row['transfer_group_id']) $allGroupIds[] = (int)$row['transfer_group_id'];
            }
        }

        $groupNotes = [];
        if (!empty($allGroupIds)) {
            $uniqueGroupIds = array_unique($allGroupIds);
            $groupsStr = implode(',', $uniqueGroupIds);
            $groupRows = $MsaDB->query("
                SELECT tg.id, tg.params, tgt.template 
                FROM inventory__transfer_groups tg 
                LEFT JOIN ref__transfer_group_types tgt ON tg.type_id = tgt.id 
                WHERE tg.id IN ($groupsStr)
            ");
            foreach ($groupRows as $gr) {
                $groupNotes[$gr['id']] = TransferGroupManager::formatNote($gr['template'] ?? '', $gr['params'] ?? '[]');
            }
        }

        $commissionsData = [];
        $transfersByCommission = [];
        $originalTransferGroups = [];

        foreach ($commissionIds as $cId) {
            if (!isset($commissions[$cId])) continue;
            $commission = $commissions[$cId];
            $row = $commission->commissionValues;
            $deviceType = $commission->deviceType;
            $bomId = (int)$row['bom_id'];
            
            $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
            $deviceId = $deviceBom->deviceId;
            $deviceName = $nameLists[$deviceType][$deviceId] ?? "Unknown";

            $unreturned = (int)$row['qty_produced'] - (int)$row['qty_returned'];

            $commissionsData[$cId] = [
                'id' => $cId,
                'deviceName' => $deviceName,
                'deviceType' => $deviceType,
                'bomId' => $bomId,
                'qty' => (int)$row['qty'],
                'qtyProduced' => (int)$row['qty_produced'],
                'qtyReturned' => (int)$row['qty_returned'],
                'qtyUnreturned' => $unreturned,
                'isCancelled' => (bool)$row['is_cancelled'],
                'state' => $row['state'],
                'priority' => $row['priority'],
                'createdAt' => $row['created_at']
            ];

            $originalTransferGroups[$cId] = null;
            foreach ($componentTypes as $type) {
                if (isset($inventoryData[$type][$cId])) {
                    foreach ($inventoryData[$type][$cId] as $compId => $transfers) {
                        foreach ($transfers as $t) {
                            if ($t['is_cancelled'] == 0 && $t['qty'] > 0) {
                                if ($originalTransferGroups[$cId] === null || $t['transfer_group_id'] < $originalTransferGroups[$cId]) {
                                    $originalTransferGroups[$cId] = (int)$t['transfer_group_id'];
                                }
                            }
                        }
                    }
                }
            }

            $transfersByCommission[$cId] = [];

            foreach ($flatBom as $component) {
                $componentType = null;
                $componentId = null;

                if ($component['parts_id']) { $componentType = 'parts'; $componentId = $component['parts_id']; }
                elseif ($component['sku_id']) { $componentType = 'sku'; $componentId = $component['sku_id']; }
                elseif ($component['tht_id']) { $componentType = 'tht'; $componentId = $component['tht_id']; }
                elseif ($component['smd_id']) { $componentType = 'smd'; $componentId = $component['smd_id']; }

                if (!$componentType) continue;

                $transfers = $inventoryData[$componentType][$cId][$componentId] ?? [];

                foreach ($transfers as $transfer) {
                    if ($transfer['qty'] <= 0) continue;

                    $qtyTransferred = (float)$transfer['qty'];
                    $qtyPerItem = (float)$component['quantity'];
                    $qtyUsed = (float)$row['qty_produced'] * $qtyPerItem;
                    $qtyAvailable = max(0, $qtyTransferred - $qtyUsed);
                    $isCancelled = (bool)$transfer['is_cancelled'];

                    if ($qtyAvailable <= 0 && !$isCancelled) continue;

                    $transferGroupId = $transfer['transfer_group_id'] ? (int)$transfer['transfer_group_id'] : null;
                    $sources = [];
                    $transferDetails = [];

                    if ($transferGroupId) {
                        $remainingQty = $qtyAvailable;
                        $sourcesForThisGroup = [];
                        
                        if (isset($inventoryData[$componentType][$cId][$componentId])) {
                            foreach ($inventoryData[$componentType][$cId][$componentId] as $t) {
                                if ($t['transfer_group_id'] == $transferGroupId && $t['qty'] < 0 && $t['is_cancelled'] == 0) {
                                    $sourcesForThisGroup[] = $t;
                                }
                            }
                        }
                        
                        usort($sourcesForThisGroup, function($a, $b) use ($mainRow) {
                            $aIsMain = $a['sub_magazine_id'] == $mainRow['warehouse_from_id'];
                            $bIsMain = $b['sub_magazine_id'] == $mainRow['warehouse_from_id'];
                            if ($aIsMain != $bIsMain) return $bIsMain - $aIsMain;
                            return abs((float)$b['qty']) - abs((float)$a['qty']);
                        });

                        foreach ($sourcesForThisGroup as $src) {
                            $sourceQty = abs((float)$src['qty']);
                            $allocatedQty = min($remainingQty, $sourceQty);
                            $remainingQty -= $allocatedQty;

                            $sourceData = [
                                'warehouseId' => (int)$src['sub_magazine_id'],
                                'warehouseName' => $magazines[$src['sub_magazine_id']] ?? "Unknown",
                                'quantity' => (float)$allocatedQty,
                                'originalQty' => (float)$src['qty'],
                                'isMainWarehouse' => $src['sub_magazine_id'] == $mainRow['warehouse_from_id']
                            ];

                            $sources[] = $sourceData;
                            $transferDetails[] = $sourceData;
                        }

                        $transferDetails[] = [
                            'warehouseId' => (int)$transfer['sub_magazine_id'],
                            'warehouseName' => $magazines[$transfer['sub_magazine_id']] ?? "Unknown",
                            'quantity' => (float)$qtyAvailable,
                            'originalQty' => (float)$transfer['qty'],
                            'isMainWarehouse' => false
                        ];
                    }

                    if (empty($sources)) {
                        $sources[] = [
                            'warehouseId' => (int)$mainRow['warehouse_from_id'],
                            'warehouseName' => $magazines[$mainRow['warehouse_from_id']] ?? "Unknown",
                            'quantity' => $qtyAvailable,
                            'isMainWarehouse' => true
                        ];
                    }

                    $isCancellationGroup = ($transferGroupId && $originalTransferGroups[$cId] && $transferGroupId != $originalTransferGroups[$cId]);
                    $transferGroupNotes = $isCancellationGroup ? ($groupNotes[$transferGroupId] ?? '') : '';

                    $transfersByCommission[$cId][] = [
                        'transferId' => (int)$transfer['id'],
                        'commissionId' => (int)$cId,
                        'componentType' => $componentType,
                        'componentId' => (int)$componentId,
                        'componentName' => $nameLists[$componentType][$componentId] ?? "Unknown",
                        'qtyTransferred' => $qtyTransferred,
                        'qtyUsed' => $qtyUsed,
                        'qtyAvailable' => $qtyAvailable,
                        'qtyPerItem' => $qtyPerItem,
                        'sources' => $sources,
                        'transferDetails' => $transferDetails,
                        'destinationWarehouseId' => (int)$transfer['sub_magazine_id'],
                        'transferGroupId' => $transferGroupId,
                        'isCancelled' => $isCancelled,
                        'isCancellationGroup' => $isCancellationGroup,
                        'transferGroupNotes' => $transferGroupNotes
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'clickedCommissionId' => $commissionId,
            'isGrouped' => $isGrouped,
            'commissionsData' => $commissionsData,
            'transfersByCommission' => $transfersByCommission
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getDetailsData($MsaDB) {
    try {
        $commissionId = $_POST['commissionId'] ?? $_GET['commissionId'] ?? '';
        $isGrouped = isset($_POST['isGrouped']) && ($_POST['isGrouped'] === 'true' || $_POST['isGrouped'] === '1' || $_POST['isGrouped'] === 1);
        $groupedIds = $_POST['groupedIds'] ?? $_GET['groupedIds'] ?? '';

        $commissionRepository = new CommissionRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);
        $magazines = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name");

        if ($isGrouped && !empty($groupedIds)) {
            $commissionIds = array_map('intval', explode(',', $groupedIds));
        } else {
            $commissionIds = [(int)$commissionId];
        }

        $commissions = $commissionRepository->getCommissionsByIds($commissionIds);
        if (empty($commissions)) {
            echo json_encode(['success' => true, 'details' => []]);
            return;
        }

        $detailsByCommission = [];
        $nameLists = [
            'parts' => $MsaDB->readIdName("list__parts"),
            'sku' => $MsaDB->readIdName("list__sku"),
            'smd' => $MsaDB->readIdName("list__smd"),
            'tht' => $MsaDB->readIdName("list__tht")
        ];

        $mainCommission = reset($commissions);
        $deviceType = $mainCommission->deviceType;
        $deviceNameList = $MsaDB->readIdName("list__".$deviceType);

        foreach ($commissionIds as $cId) {
            if (!isset($commissions[$cId])) continue;
            $commission = $commissions[$cId];
            $row = $commission->commissionValues;
            $bomId = $row['bom_id'];

            $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
            $deviceId = $deviceBom->deviceId;
            $deviceName = $deviceNameList[$deviceId] ?? "Unknown";

            $detailsByCommission[$cId] = [
                'id' => $cId,
                'deviceName' => $deviceName,
                'state' => $row['state'],
                'createdAt' => $row['created_at'],
                'targetComponentId' => $deviceId,
                'movements' => []
            ];
        }

        $idsStr = implode(',', $commissionIds);
        
        $unionParts = [];
        $componentTypes = ['parts', 'sku', 'smd', 'tht'];
        foreach ($componentTypes as $type) {
            if ($type === $deviceType) continue;
            $unionParts[] = "SELECT '$type' as type, commission_id, {$type}_id as component_id, sub_magazine_id, qty, timestamp, is_cancelled, comment, 0 as isProduced FROM inventory__{$type} WHERE commission_id IN ($idsStr)";
        }
        $unionParts[] = "SELECT 'device' as type, commission_id, {$deviceType}_id as component_id, sub_magazine_id, qty, timestamp, is_cancelled, comment, 1 as isProduced FROM inventory__{$deviceType} WHERE commission_id IN ($idsStr)";
        
        $query = implode(" UNION ALL ", $unionParts) . " ORDER BY timestamp ASC";
        
        $allMovements = $MsaDB->query($query, PDO::FETCH_ASSOC);

        foreach ($allMovements as $m) {
            $cId = (int)$m['commission_id'];
            if (!isset($detailsByCommission[$cId])) continue;
            
            $type = $m['type'];
            $displayType = ($type === 'device') ? $deviceType : $type;
            $compId = (int)$m['component_id'];
            
            $compName = "Unknown";
            if ($type === 'device') {
                $compName = $detailsByCommission[$cId]['deviceName'];
            } else {
                $compName = $nameLists[$type][$compId] ?? "Unknown";
            }

            $detailsByCommission[$cId]['movements'][] = [
                'type' => $type,
                'displayType' => $displayType,
                'component_id' => $compId,
                'component_name' => $compName,
                'warehouseName' => $magazines[$m['sub_magazine_id']] ?? 'Nieznany',
                'qty' => (float)$m['qty'],
                'timestamp' => $m['timestamp'],
                'isCancelled' => (bool)$m['is_cancelled'],
                'comment' => $m['comment'],
                'isProduced' => (bool)$m['isProduced']
            ];
        }

        echo json_encode([
            'success' => true,
            'details' => $detailsByCommission
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
