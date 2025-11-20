<?php
use Atte\DB\MsaDB;
use Atte\Utils\UserRepository;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');

try {
    $MsaDB = MsaDB::getInstance();

    $usersName = $MsaDB->query("SELECT name, surname FROM user ORDER BY user_id ASC", PDO::FETCH_ASSOC);
    $usersId = $MsaDB->query("SELECT user_id FROM user ORDER BY user_id ASC", PDO::FETCH_COLUMN);
    $users = array_combine($usersId, $usersName);

    $userRepository = new UserRepository($MsaDB);
    $currentUser = $userRepository->getUserById($_SESSION["userid"]);

    $bomRepository = new BomRepository($MsaDB);
    $commissions = $currentUser->getActiveCommissions();

    $groupTogether = isset($_POST['groupTogether']) ? $_POST['groupTogether'] === 'true' : false;

    function getPriorityColor($priority) {
        $colors = [
            'none' => 'transparent',
            'standard' => 'green',
            'urgent' => 'yellow',
            'critical' => 'red'
        ];
        return $colors[$priority] ?? 'transparent';
    }

    $commissionsData = [];
    $potentialGroupsMap = [];

    foreach($commissions as $commission) {
        $commissionDeviceType = $commission->deviceType;
        $commissionValues = $commission->commissionValues;

        $valuesToPrint = [];
        $valuesToPrint['id'] = $commissionValues['id'];
        $valuesToPrint['priority'] = $commissionValues["priority"];
        $valuesToPrint['color'] = getPriorityColor($valuesToPrint['priority']);
        $valuesToPrint['state'] = $commissionValues['state'];
        $valuesToPrint['cardClass'] = $commissionValues['state'] == 'active' ? '' : 'list-group-item-secondary';
        $valuesToPrint['tableClass'] = $commissionValues['state'] != 'active' ? 'table-light' : '';

        $receivers = $commission->getReceivers();
        $valuesToPrint['hideButton'] = count($receivers) == 1 ? "visibility: hidden;" : '';
        $valuesToPrint['receivers'] = [];
        $valuesToPrint['receiversIds'] = $receivers;

        foreach($receivers as $receiver) {
            $valuesToPrint['receivers'][] = $users[$receiver]['name']." ".$users[$receiver]['surname'];
        }

        $valuesToPrint['magazineTo'] = $commissionValues['warehouse_to_id'];
        $valuesToPrint['deviceBomId'] = $commissionValues['bom_id'];

        $bom = $bomRepository->getBomById($commissionDeviceType, $valuesToPrint['deviceBomId']);
        $bom->getNameAndDescription();

        $valuesToPrint['deviceId'] = $bom->deviceId;
        $valuesToPrint['deviceName'] = $bom->name;
        $valuesToPrint['deviceDescription'] = $bom->description;
        $valuesToPrint['deviceLaminate'] = $bom->laminateName ?? '';
        $valuesToPrint['deviceVersion'] = $bom->version;
        $valuesToPrint['quantity'] = $commissionValues['qty'];
        $valuesToPrint['quantityProduced'] = $commissionValues['qty_produced'];
        $valuesToPrint['quantityReturned'] = $commissionValues['qty_returned'];
        $valuesToPrint['timestampCreated'] = $commissionValues['created_at'];

        $receiversKey = implode(',', $receivers);
        $groupKey = $commissionDeviceType.'_'.$valuesToPrint['deviceBomId'].'_'.$valuesToPrint['magazineTo'].'_'.$receiversKey.'_'.$valuesToPrint['priority'];

        if(!isset($potentialGroupsMap[$groupKey])) {
            $potentialGroupsMap[$groupKey] = 1;
        } else {
            $potentialGroupsMap[$groupKey]++;
        }

        $commissionsData[] = [
            'deviceType' => $commissionDeviceType,
            'valuesToPrint' => $valuesToPrint,
            'groupKey' => $groupKey
        ];
    }

    if($groupTogether) {
        $groupedCommissions = [];

        foreach($commissionsData as $data) {
            $groupKey = $data['groupKey'];
            $valuesToPrint = $data['valuesToPrint'];

            if(!isset($groupedCommissions[$groupKey])) {
                $groupedCommissions[$groupKey] = [
                    'deviceType' => $data['deviceType'],
                    'ids' => [$valuesToPrint['id']],
                    'totalQty' => (int)$valuesToPrint['quantity'],
                    'totalProduced' => (int)$valuesToPrint['quantityProduced'],
                    'totalReturned' => (int)$valuesToPrint['quantityReturned'],
                    'firstCommission' => $valuesToPrint
                ];
            } else {
                $groupedCommissions[$groupKey]['ids'][] = $valuesToPrint['id'];
                $groupedCommissions[$groupKey]['totalQty'] += (int)$valuesToPrint['quantity'];
                $groupedCommissions[$groupKey]['totalProduced'] += (int)$valuesToPrint['quantityProduced'];
                $groupedCommissions[$groupKey]['totalReturned'] += (int)$valuesToPrint['quantityReturned'];
            }
        }

        $result = [];
        foreach($groupedCommissions as $group) {
            $isGrouped = count($group['ids']) > 1;
            $valuesToPrint = $group['firstCommission'];
            $valuesToPrint['quantity'] = $group['totalQty'];
            $valuesToPrint['quantityProduced'] = $group['totalProduced'];
            $valuesToPrint['quantityReturned'] = $group['totalReturned'];
            $valuesToPrint['isGrouped'] = $isGrouped;
            $valuesToPrint['groupedCount'] = count($group['ids']);
            $valuesToPrint['groupedIds'] = $group['ids'];

            $result[] = [
                'deviceType' => $group['deviceType'],
                'valuesToPrint' => $valuesToPrint
            ];
        }

        echo json_encode(['success' => true, 'data' => $result]);
    } else {
        foreach($commissionsData as &$data) {
            $data['valuesToPrint']['isGrouped'] = false;
            $data['valuesToPrint']['canBeGrouped'] = $potentialGroupsMap[$data['groupKey']] > 1;
            $data['valuesToPrint']['potentialGroupCount'] = $potentialGroupsMap[$data['groupKey']];
        }

        echo json_encode(['success' => true, 'data' => $commissionsData]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}