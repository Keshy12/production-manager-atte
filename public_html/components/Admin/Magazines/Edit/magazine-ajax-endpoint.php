<?php
use Atte\DB\MsaDB;
use Atte\Utils\MagazineRepository;
use Atte\Utils\UserRepository;
use Atte\Utils\MagazineActionHandler;

// Check if user is admin
if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] != true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Brak uprawnień']);
    exit();
}

// Check if request is POST and has JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda nieobsługiwana']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowy format JSON']);
    exit();
}

$MsaDB = MsaDB::getInstance();
$magazineRepository = new MagazineRepository($MsaDB);
$userRepository = new UserRepository($MsaDB);
$transferGroupManager = new Atte\Utils\TransferGroupManager($MsaDB);
$actionHandler = new MagazineActionHandler($MsaDB, $magazineRepository, $userRepository, $transferGroupManager);


// Action handlers array
$actionHandlers = [
    'add_magazine' => function($input) use ($magazineRepository, $actionHandler) {
        if (empty($input['sub_magazine_name']) || empty($input['type_id'])) {
            return ['success' => false, 'message' => 'Wszystkie pola są wymagane'];
        }

        $typeId = (int)$input['type_id'];
        $formattedName = $actionHandler->formatMagazineName($input['sub_magazine_name'], $typeId);

        $id = $magazineRepository->createMagazine($formattedName, $typeId);
        return [
            'success' => true,
            'message' => 'Magazyn został dodany pomyślnie',
            'magazine_id' => $id
        ];
    },

    'edit_magazine' => function($input) use ($magazineRepository, $actionHandler, $MsaDB) {
        if (empty($input['sub_magazine_id']) || empty($input['sub_magazine_name']) || empty($input['type_id'])) {
            return ['success' => false, 'message' => 'Wszystkie pola są wymagane'];
        }

        $magazineId = (int)$input['sub_magazine_id'];
        $typeId = (int)$input['type_id'];

        // Get original name to preserve SUB MAG prefix if editing type 2
        $originalResult = $MsaDB->query("SELECT sub_magazine_name FROM magazine__list WHERE sub_magazine_id = {$magazineId}", \PDO::FETCH_ASSOC);
        $originalName = !empty($originalResult) ? $originalResult[0]['sub_magazine_name'] : '';

        $formattedName = $actionHandler->formatMagazineName($input['sub_magazine_name'], $typeId, true, $originalName);

        $magazineRepository->updateMagazine($magazineId, $formattedName, $typeId);
        return ['success' => true, 'message' => 'Magazyn został zaktualizowany pomyślnie'];
    },

    'toggle_magazine_status' => function($input) use ($magazineRepository) {
        if (empty($input['sub_magazine_id'])) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator magazynu'];
        }

        $magazineId = (int)$input['sub_magazine_id'];
        $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;

        $magazineRepository->toggleMagazineStatus($magazineId, $isActive);
        $statusMessage = $isActive ? 'włączony' : 'wyłączony';
        return ['success' => true, 'message' => "Magazyn został {$statusMessage} pomyślnie"];
    },

    'disable_magazine_with_users' => function($input) use ($magazineRepository, $actionHandler, $MsaDB) {
        if (empty($input['sub_magazine_id']) || empty($input['user_action'])) {
            return ['success' => false, 'message' => 'Nieprawidłowe dane'];
        }

        $magazineId = (int)$input['sub_magazine_id'];
        $userAction = $input['user_action'];

        try {
            $MsaDB->db->beginTransaction();

            $users = $magazineRepository->getUsersAssignedToMagazine($magazineId);
            $userMessage = $actionHandler->handleUserActions($users, $userAction);

            $magazineRepository->toggleMagazineStatus($magazineId, false);
            $MsaDB->db->commit();

            return [
                'success' => true,
                'message' => "Magazyn został wyłączony pomyślnie, {$userMessage}"
            ];
        } catch (Exception $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    },

    'add_type' => function($input) use ($magazineRepository) {
        if (empty($input['type_name'])) {
            return ['success' => false, 'message' => 'Nazwa typu jest wymagana'];
        }

        $id = $magazineRepository->createMagazineType($input['type_name']);
        return [
            'success' => true,
            'message' => 'Typ magazynu został dodany pomyślnie',
            'type_id' => $id
        ];
    },

    'get_magazine_users' => function($input) use ($magazineRepository, $actionHandler) {
        if ($input['magazine_id'] !== 0 && empty($input['magazine_id'])) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator magazynu'];
        }

        $magazineId = (int)$input['magazine_id'];
        $users = $magazineRepository->getUsersAssignedToMagazine($magazineId);
        $usersArray = $actionHandler->formatUsersArray($users);

        return [
            'success' => true,
            'users' => $usersArray
        ];
    },

    'assign_user' => function($input) use ($magazineRepository) {
        if (empty($input['user_id']) || ($input['magazine_id'] !== '0' && empty($input['magazine_id']))) {
            return ['success' => false, 'message' => 'Nieprawidłowe dane'];
        }

        $userId = (int)$input['user_id'];
        $magazineId = (int)$input['magazine_id'];

        $magazineRepository->assignUserToMagazine($userId, $magazineId);
        return ['success' => true, 'message' => 'Użytkownik został przypisany do magazynu'];
    },

    'unassign_user' => function($input) use ($magazineRepository) {
        if (empty($input['user_id'])) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator użytkownika'];
        }

        $userId = (int)$input['user_id'];
        $magazineRepository->assignUserToMagazine($userId, null);
        return ['success' => true, 'message' => 'Użytkownik został odłączony od magazynu'];
    },

    'disable_magazine_with_user_choice' => function($input) use ($magazineRepository, $actionHandler, $MsaDB) {
        if (empty($input['sub_magazine_id']) || empty($input['user_action'])) {
            return ['success' => false, 'message' => 'Nieprawidłowe dane'];
        }

        $magazineId = (int)$input['sub_magazine_id'];
        $userAction = $input['user_action'];

        try {
            $MsaDB->db->beginTransaction();

            $users = $magazineRepository->getUsersAssignedToMagazine($magazineId);
            $userMessage = $actionHandler->handleUserActions($users, $userAction);

            $magazineRepository->toggleMagazineStatus($magazineId, false);
            $MsaDB->db->commit();

            return [
                'success' => true,
                'message' => "Magazyn został wyłączony pomyślnie, {$userMessage}"
            ];
        } catch (Exception $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    },

    'get_magazine_inventory' => function($input) use ($actionHandler) {
        if (empty($input['magazine_id'])) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator magazynu'];
        }

        $magazineId = (int)$input['magazine_id'];
        $inventory = $actionHandler->getMagazineInventory($magazineId);

        return [
            'success' => true,
            'inventory' => $inventory,
            'total_items' => count($inventory)
        ];
    },

    'get_available_magazines' => function($input) use ($actionHandler) {
        if (empty($input['exclude_magazine_id'])) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator magazynu'];
        }

        $excludeMagazineId = (int)$input['exclude_magazine_id'];
        $availableMagazines = $actionHandler->getAvailableMagazinesExcluding($excludeMagazineId);

        return [
            'success' => true,
            'magazines' => $availableMagazines
        ];
    },

    'disable_magazine_with_inventory_choice' => function($input) use ($magazineRepository, $actionHandler, $MsaDB) {
        if (empty($input['sub_magazine_id']) || empty($input['user_action'])) {
            return ['success' => false, 'message' => 'Nieprawidłowe dane'];
        }

        $magazineId = (int)$input['sub_magazine_id'];
        $userAction = $input['user_action'];
        $inventoryAction = $input['inventory_action'] ?? 'nothing';
        $targetMagazineId = isset($input['target_magazine_id']) ? (int)$input['target_magazine_id'] : null;

        try {
            $MsaDB->db->beginTransaction();

            $users = $magazineRepository->getUsersAssignedToMagazine($magazineId);
            $userMessage = $actionHandler->handleUserActions($users, $userAction);
            $inventoryMessage = $actionHandler->handleInventoryActions($magazineId, $inventoryAction, $targetMagazineId);

            $magazineRepository->toggleMagazineStatus($magazineId, false);
            $MsaDB->db->commit();

            return [
                'success' => true,
                'message' => "Magazyn został wyłączony pomyślnie, {$userMessage}{$inventoryMessage}"
            ];
        } catch (Exception $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    },

    'get_next_submag_number' => function($input) use ($actionHandler) {
        $nextNumber = $actionHandler->getNextSubMagNumber();
        return [
            'success' => true,
            'next_number' => $nextNumber
        ];
    }
];

try {
    $action = $input['action'] ?? '';

    $handler = $actionHandlers[$action] ?? function() {
        return ['success' => false, 'message' => 'Nieznana akcja'];
    };

    $result = $handler($input);
    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Błąd: ' . $e->getMessage()]);
}