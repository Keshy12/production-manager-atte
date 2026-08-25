<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?Vendor {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id,
                       name,
                       address,
                       additional_data AS additionalData,
                       lead_time_days AS leadTimeDays,
                       is_active,
                       comment,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                FROM `list__vendor`
                WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Vendor($MsaDB, $row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE is_active = 1" : "";
        $sql = "SELECT id,
                       name,
                       address,
                       additional_data AS additionalData,
                       lead_time_days AS leadTimeDays,
                       is_active,
                       comment,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                FROM `list__vendor`
                {$where}
                ORDER BY name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Vendor($MsaDB, $row);
        }
        return $result;
    }

    public function create(string $name, ?string $address = null, ?string $additionalData = null, ?int $leadTimeDays = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        return $MsaDB->insert(
            'list__vendor',
            ['name', 'address', 'additional_data', 'lead_time_days', 'is_active', 'comment'],
            [$name, $address, $additionalData, $leadTimeDays, 1, $comment]
        );
    }

    public function update(int $id, string $name, ?string $address, ?string $additionalData, ?int $leadTimeDays, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        return $MsaDB->update(
            'list__vendor',
            [
                'name' => $name,
                'address' => $address,
                'additional_data' => $additionalData,
                'lead_time_days' => $leadTimeDays,
                'comment' => $comment,
            ],
            'id',
            $id
        );
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__vendor',
            ['is_active' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function countVendorParts(int $vendorId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_part` WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    public function countSuppliers(int $vendorId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_supplier` WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }
}
