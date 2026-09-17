<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorSupplierRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?VendorSupplier {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id,
                       vendor_id AS vendorId,
                       name,
                       job_title AS jobTitle,
                       phone,
                       email,
                       isActive,
                       comment
                FROM `list__vendor_supplier`
                WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new VendorSupplier($row);
    }

    public function getByVendor(int $vendorId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE vendor_id = ? AND isActive = 1" : "WHERE vendor_id = ?";
        $sql = "SELECT id,
                       vendor_id AS vendorId,
                       name,
                       job_title AS jobTitle,
                       phone,
                       email,
                       isActive,
                       comment
                FROM `list__vendor_supplier`
                {$where}
                ORDER BY name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new VendorSupplier($row);
        }
        return $result;
    }

    public function create(int $vendorId, string $name, ?string $jobTitle = null, ?string $phone = null, ?string $email = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($vendorId <= 0) {
            throw new \InvalidArgumentException("VendorSupplier vendorId must be positive.");
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("VendorSupplier name cannot be empty.");
        }
        return $MsaDB->insert(
            'list__vendor_supplier',
            ['vendor_id', 'name', 'job_title', 'phone', 'email', 'isActive', 'comment'],
            [$vendorId, $name, $jobTitle, $phone, $email, 1, $comment]
        );
    }

    public function update(int $id, string $name, ?string $jobTitle, ?string $phone, ?string $email, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("VendorSupplier name cannot be empty.");
        }
        return $MsaDB->update(
            'list__vendor_supplier',
            [
                'name' => $name,
                'job_title' => $jobTitle,
                'phone' => $phone,
                'email' => $email,
                'comment' => $comment,
            ],
            'id',
            $id
        );
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__vendor_supplier',
            ['isActive' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    /**
     * Zwraca kontakty dostawcy posortowane: kontakty oznaczone jako
     * główne (`is_primary = 1`) najpierw, potem alfabetycznie po nazwie.
     * Domyślnie filtruje po `isActive = 1` — wyślij `$onlyActive = false`
     * jeśli potrzebujesz pełnej listy (np. w panelu admina).
     *
     * Wymaga kolumny `is_primary` dodanej przez krok P5+
     * w migrate-to-procurement-schema.php — na bazach sprzed migracji
     * kolumna nie istnieje i zapytanie rzuci wyjątkiem PDO. Sortowanie
     * wykonywane w SQL, więc encja VendorSupplier nie musi znać tego
     * pola — wystarczy, że DB zwraca wiersze w odpowiedniej kolejności.
     *
     * @return VendorSupplier[]
     */
    public function listByVendor(int $vendorId, bool $onlyActive = true): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE vendor_id = ? AND isActive = 1" : "WHERE vendor_id = ?";
        $sql = "SELECT id,
                       vendor_id AS vendorId,
                       name,
                       job_title AS jobTitle,
                       phone,
                       email,
                       isActive,
                       comment
                FROM `list__vendor_supplier`
                {$where}
                ORDER BY is_primary DESC, name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new VendorSupplier($row);
        }
        return $result;
    }

    /**
     * Ustawia dany kontakt jako główny dla dostawcy — atomowo czyści
     * wszystkie inne flagi `is_primary` tego samego vendor_id i włącza
     * flagę na wybranym rekordzie. Rzuca LogicException, jeśli rekord
     * `$id` nie należy do `$vendorId` (obrona przed pomyłką w
     * wywołującym kodzie).
     *
     * Kolumna `is_primary` wymaga migracji P5+. Na starszych bazach
     * zapytanie UPDATE rzuci wyjątkiem PDO.
     */
    public function setPrimary(int $id, int $vendorId): bool {
        $MsaDB = $this->MsaDB;

        // 1. Weryfikacja właściciela rekordu — tania ochrona przed
        //    przestawieniem flagi na kontakcie innego dostawcy.
        $ownerStmt = $MsaDB->db->prepare(
            "SELECT vendor_id AS vendorId FROM `list__vendor_supplier` WHERE id = ?"
        );
        $ownerStmt->execute([$id]);
        $ownerRow = $ownerStmt->fetch(\PDO::FETCH_ASSOC);
        if ($ownerRow === false) {
            throw new \LogicException(
                "Kontakt dostawcy #{$id} nie istnieje."
            );
        }
        if ((int)$ownerRow['vendorId'] !== $vendorId) {
            throw new \LogicException(
                "Kontakt dostawcy #{$id} nie należy do dostawcy #{$vendorId}."
            );
        }

        // 2. Atomowo: zdejmij is_primary u pozostałych + ustaw na
        //    wybranym. Jeden beginTransaction/commit/rollBack zgodnie
        //    z konwencją createReceipt.
        $MsaDB->db->beginTransaction();
        try {
            $MsaDB->update(
                'list__vendor_supplier',
                ['is_primary' => 0],
                'vendor_id',
                $vendorId
            );
            $MsaDB->update(
                'list__vendor_supplier',
                ['is_primary' => 1],
                'id',
                $id
            );
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zwraca liczbę kontaktów oznaczonych jako główne dla danego
     * dostawcy. Normalnie 0 lub 1 — setPrimary utrzymuje ten
     * niezmiennik. Wyższe wartości to sygnał regresji (np. ręczna
     * edycja bazy albo niezakończony wcześniejszy setPrimary).
     */
    public function countPrimariesForVendor(int $vendorId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare(
            "SELECT COUNT(*) AS c
               FROM `list__vendor_supplier`
              WHERE vendor_id = ? AND is_primary = 1"
        );
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? 0 : (int)$row['c'];
    }
}
