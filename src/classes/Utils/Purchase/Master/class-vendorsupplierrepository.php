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
                       is_active,
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
        $where = $onlyActive ? "WHERE vendor_id = ? AND is_active = 1" : "WHERE vendor_id = ?";
        $sql = "SELECT id,
                       vendor_id AS vendorId,
                       name,
                       job_title AS jobTitle,
                       phone,
                       email,
                       is_active,
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
            ['vendor_id', 'name', 'job_title', 'phone', 'email', 'is_active', 'comment'],
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
            ['is_active' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }
}
