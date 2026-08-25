<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class ProducerRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?Producer {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name, is_active, comment
                FROM `list__producer`
                WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Producer($MsaDB, $row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE is_active = 1" : "";
        $sql = "SELECT id, name, is_active, comment
                FROM `list__producer`
                {$where}
                ORDER BY name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Producer($MsaDB, $row);
        }
        return $result;
    }

    public function getByName(string $name): ?Producer {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name, is_active, comment
                FROM `list__producer`
                WHERE name = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Producer($MsaDB, $row);
    }

    public function create(string $name, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Producer name cannot be empty.");
        }
        return $MsaDB->insert(
            'list__producer',
            ['name', 'is_active', 'comment'],
            [$name, 1, $comment]
        );
    }

    public function update(int $id, string $name, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Producer name cannot be empty.");
        }
        return $MsaDB->update(
            'list__producer',
            [
                'name' => $name,
                'comment' => $comment,
            ],
            'id',
            $id
        );
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__producer',
            ['is_active' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function countVendorParts(int $producerId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_part` WHERE producer_id = ?");
        $stmt->execute([$producerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }
}
