<?php
namespace Atte\Utils;

use Atte\DB\MsaDB;
use Exception;

class MagazineActionHandler
{
    private $MsaDB;
    private $magazineRepository;
    private $userRepository;

    public function __construct(MsaDB $MsaDB, MagazineRepository $magazineRepository, UserRepository $userRepository)
    {
        $this->MsaDB = $MsaDB;
        $this->magazineRepository = $magazineRepository;
        $this->userRepository = $userRepository;
    }

    public function getNextSubMagNumber(): int
    {
        $result = $this->MsaDB->query("SELECT sub_magazine_name FROM magazine__list WHERE type_id = 2 AND sub_magazine_name LIKE 'SUB MAG %:%'");

        $maxNumber = 0;
        foreach ($result as $row) {
            if (preg_match('/^SUB MAG (\d+):/', $row['sub_magazine_name'], $matches)) {
                $number = (int)$matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        return $maxNumber + 1;
    }

    public function formatMagazineName(string $name, int $typeId, bool $isEdit = false, string $originalName = ''): string
    {
        if ($typeId == 2) {
            if ($isEdit) {
                if (preg_match('/^(SUB MAG \d+:)\s*(.*)/', $originalName, $matches)) {
                    $prefix = $matches[1];
                    return trim($prefix . ' ' . trim($name));
                } else {
                    $nextNumber = $this->getNextSubMagNumber();
                    return "SUB MAG {$nextNumber}: " . trim($name);
                }
            } else {
                $nextNumber = $this->getNextSubMagNumber();
                return "SUB MAG {$nextNumber}: " . trim($name);
            }
        }

        return trim($name);
    }

    public function getMagazineInventory(int $magazineId): array
    {
        $inventory = [];

        $queries = [
            "SELECT p.name, p.description, SUM(i.quantity) as total_quantity, 'parts' as type, p.id as item_id
             FROM inventory__parts i
             JOIN list__parts p ON i.parts_id = p.id
             WHERE i.sub_magazine_id = {$magazineId} AND p.isActive = 1
             GROUP BY i.parts_id
             HAVING total_quantity > 0",

            "SELECT s.name, s.description, SUM(i.quantity) as total_quantity, 'smd' as type, s.id as item_id
             FROM inventory__smd i
             JOIN list__smd s ON i.smd_id = s.id
             WHERE i.sub_magazine_id = {$magazineId} AND s.isActive = 1
             GROUP BY i.smd_id
             HAVING total_quantity > 0",

            "SELECT t.name, t.description, SUM(i.quantity) as total_quantity, 'tht' as type, t.id as item_id
             FROM inventory__tht i
             JOIN list__tht t ON i.tht_id = t.id
             WHERE i.sub_magazine_id = {$magazineId} AND t.isActive = 1
             GROUP BY i.tht_id
             HAVING total_quantity > 0",

            "SELECT s.name, s.description, SUM(i.quantity) as total_quantity, 'sku' as type, s.id as item_id
             FROM inventory__sku i
             JOIN list__sku s ON i.sku_id = s.id
             WHERE i.sub_magazine_id = {$magazineId} AND s.isActive = 1
             GROUP BY i.sku_id
             HAVING total_quantity > 0"
        ];

        foreach ($queries as $query) {
            $results = $this->MsaDB->query($query, \PDO::FETCH_ASSOC);
            $inventory = array_merge($inventory, $results);
        }

        return $inventory;
    }

    public function transferInventoryToMagazine(int $fromMagazineId, int $toMagazineId): string
    {
        // Verify target magazine exists and is active
        $targetMagazineResult = $this->MsaDB->query("SELECT sub_magazine_name FROM magazine__list WHERE sub_magazine_id = {$toMagazineId} AND (isActive IS NULL OR isActive = 1)", \PDO::FETCH_ASSOC);
        if (empty($targetMagazineResult)) {
            throw new Exception('Magazyn docelowy nie istnieje lub jest nieaktywny');
        }

        $userId = $_SESSION['user_id'] ?? 1;

        try {
            $transferQueries = [
                // Insert positive quantities to target magazine
                "INSERT INTO inventory__parts (parts_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT parts_id, {$userId}, {$toMagazineId}, SUM(quantity), NOW(), 2, 
                    'Transfer z magazynu {$fromMagazineId}', 1
             FROM inventory__parts 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY parts_id
             HAVING SUM(quantity) > 0",

                // Insert negative quantities to source magazine
                "INSERT INTO inventory__parts (parts_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT parts_id, {$userId}, {$fromMagazineId}, -SUM(quantity), NOW(), 2, 
                    'Transfer do magazynu {$toMagazineId}', 1
             FROM inventory__parts 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY parts_id
             HAVING SUM(quantity) > 0",

                // SMD - positive to target
                "INSERT INTO inventory__smd (smd_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT smd_id, {$userId}, {$toMagazineId}, SUM(quantity), NOW(), 2, 
                    'Transfer z magazynu {$fromMagazineId}', 1
             FROM inventory__smd 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY smd_id
             HAVING SUM(quantity) > 0",

                // SMD - negative to source
                "INSERT INTO inventory__smd (smd_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT smd_id, {$userId}, {$fromMagazineId}, -SUM(quantity), NOW(), 2, 
                    'Transfer do magazynu {$toMagazineId}', 1
             FROM inventory__smd 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY smd_id
             HAVING SUM(quantity) > 0",

                // THT - positive to target
                "INSERT INTO inventory__tht (tht_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT tht_id, {$userId}, {$toMagazineId}, SUM(quantity), NOW(), 2, 
                    'Transfer z magazynu {$fromMagazineId}', 1
             FROM inventory__tht 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY tht_id
             HAVING SUM(quantity) > 0",

                // THT - negative to source
                "INSERT INTO inventory__tht (tht_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT tht_id, {$userId}, {$fromMagazineId}, -SUM(quantity), NOW(), 2, 
                    'Transfer do magazynu {$toMagazineId}', 1
             FROM inventory__tht 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY tht_id
             HAVING SUM(quantity) > 0",

                // SKU - positive to target
                "INSERT INTO inventory__sku (sku_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT sku_id, {$userId}, {$toMagazineId}, SUM(quantity), NOW(), 2, 
                    'Transfer z magazynu {$fromMagazineId}', 1
             FROM inventory__sku 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY sku_id
             HAVING SUM(quantity) > 0",

                // SKU - negative to source
                "INSERT INTO inventory__sku (sku_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT sku_id, {$userId}, {$fromMagazineId}, -SUM(quantity), NOW(), 2, 
                    'Transfer do magazynu {$toMagazineId}', 1
             FROM inventory__sku 
             WHERE sub_magazine_id = {$fromMagazineId}
             GROUP BY sku_id
             HAVING SUM(quantity) > 0"
            ];

            foreach ($transferQueries as $query) {
                $result = $this->MsaDB->query($query);
                if ($result === false) {
                    throw new Exception('Błąd podczas transferu inwentarza');
                }
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $targetMagazineResult[0]['sub_magazine_name'];
    }
    public function clearMagazineInventory(int $magazineId): void
    {
        $userId = $_SESSION['user_id'] ?? 1;

        $clearQueries = [
            "INSERT INTO inventory__parts (parts_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT parts_id, {$userId}, sub_magazine_id, -SUM(quantity), NOW(), input_type_id, 
                    'Inventory cleared during magazine deactivation', 1
             FROM inventory__parts 
             WHERE sub_magazine_id = {$magazineId}
             GROUP BY parts_id, input_type_id
             HAVING SUM(quantity) > 0",

            "INSERT INTO inventory__smd (smd_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT smd_id, {$userId}, sub_magazine_id, -SUM(quantity), NOW(), input_type_id, 
                    'Inventory cleared during magazine deactivation', 1
             FROM inventory__smd 
             WHERE sub_magazine_id = {$magazineId}
             GROUP BY smd_id, input_type_id
             HAVING SUM(quantity) > 0",

            "INSERT INTO inventory__tht (tht_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT tht_id, {$userId}, sub_magazine_id, -SUM(quantity), NOW(), input_type_id, 
                    'Inventory cleared during magazine deactivation', 1
             FROM inventory__tht 
             WHERE sub_magazine_id = {$magazineId}
             GROUP BY tht_id, input_type_id
             HAVING SUM(quantity) > 0",

            "INSERT INTO inventory__sku (sku_id, user_id, sub_magazine_id, quantity, timestamp, input_type_id, comment, isVerified)
             SELECT sku_id, {$userId}, sub_magazine_id, -SUM(quantity), NOW(), input_type_id, 
                    'Inventory cleared during magazine deactivation', 1
             FROM inventory__sku 
             WHERE sub_magazine_id = {$magazineId}
             GROUP BY smd_id, input_type_id
             HAVING SUM(quantity) > 0"
        ];

        foreach ($clearQueries as $query) {
            $this->MsaDB->query($query);
        }
    }

    public function handleUserActions(array $users, string $userAction): string
    {
        $userActionHandlers = [
            'unassign' => function() use ($users) {
                foreach ($users as $user) {
                    $this->magazineRepository->assignUserToMagazine($user->userId, null);
                }
                return 'użytkownicy zostali odłączeni';
            },
            'disable' => function() use ($users) {
                $disabledUserNames = [];
                foreach ($users as $user) {
                    $this->userRepository->disableUser($user->userId);
                    $disabledUserNames[] = $user->name . ' ' . $user->surname;
                }
                return count($disabledUserNames) > 0
                    ? 'użytkownicy zostali wyłączeni: ' . implode(', ', $disabledUserNames)
                    : 'brak użytkowników do wyłączenia';
            }
        ];

        return isset($userActionHandlers[$userAction])
            ? $userActionHandlers[$userAction]()
            : 'użytkownicy pozostają przypisani';
    }

    public function handleInventoryActions(int $magazineId, string $inventoryAction, ?int $targetMagazineId = null): string
    {
        $inventoryActionHandlers = [
            'transfer' => function() use ($magazineId, $targetMagazineId) {
                if (!$targetMagazineId) {
                    throw new Exception('Nie wybrano magazynu docelowego dla transferu');
                }
                $targetMagazineName = $this->transferInventoryToMagazine($magazineId, $targetMagazineId);
                return ", inwentarz przeniesiony do magazynu: {$targetMagazineName}";
            },
            'clear' => function() use ($magazineId) {
                $this->clearMagazineInventory($magazineId);
                return ', inwentarz wyczyszczony';
            }
        ];

        return isset($inventoryActionHandlers[$inventoryAction])
            ? $inventoryActionHandlers[$inventoryAction]()
            : ', inwentarz pozostawiony bez zmian';
    }

    public function getAvailableMagazinesExcluding(int $excludeMagazineId): array
    {
        $magazines = $this->magazineRepository->getAllMagazines(onlyIsActive: true);
        $availableMagazines = [];

        foreach ($magazines as $magazine) {
            if ($magazine['sub_magazine_id'] != $excludeMagazineId) {
                $availableMagazines[] = [
                    'id' => $magazine['sub_magazine_id'],
                    'name' => $magazine['sub_magazine_name'],
                    'type_id' => $magazine['type_id']
                ];
            }
        }

        return $availableMagazines;
    }

    public function formatUsersArray(array $users): array
    {
        $usersArray = [];
        foreach ($users as $user) {
            $usersArray[] = [
                'user_id' => $user->userId,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email
            ];
        }
        return $usersArray;
    }
}