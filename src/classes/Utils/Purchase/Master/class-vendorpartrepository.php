<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorPartRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT vp.id,
                       vp.vendor_id AS vendorId,
                       vp.producer_id AS producerId,
                       vp.parts_id AS partsId,
                       vp.vendor_part_no AS vendorPartNo,
                       vp.producer_part_no AS producerPartNo,
                       vp.vendor_jm_id AS vendorJmId,
                       vp.isActive,
                       vp.comment,
                       vp.created_at AS createdAt,
                       vp.updated_at AS updatedAt,
                       v.name AS vendorName,
                       p.name AS producerName,
                       lp.name AS partName,
                       u.name AS unitName
                FROM `list__vendor_part` vp
                LEFT JOIN `list__vendor` v   ON vp.vendor_id    = v.id
                LEFT JOIN `list__producer` p ON vp.producer_id  = p.id
                LEFT JOIN `list__parts` lp   ON vp.parts_id     = lp.id
                LEFT JOIN `part__unit` u     ON vp.vendor_jm_id = u.id";
    }

    /**
     * Pull pack sizes for the given vendor_part_ids in one query.
     * Returns [vpId => ['packs' => float[], 'minPack' => ?float]].
     */
    private function loadPacksByVpId(array $vpIds): array {
        if ($vpIds === []) return [];
        $MsaDB = $this->MsaDB;
        $placeholders = implode(',', array_fill(0, count($vpIds), '?'));
        $stmt = $MsaDB->db->prepare(
            "SELECT vendor_part_id,
                    MIN(full_pack_quantity) AS min_pack,
                    GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
               FROM `list__vendor_part_pack`
              WHERE vendor_part_id IN ($placeholders)
              GROUP BY vendor_part_id"
        );
        $stmt->execute($vpIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
            $out[(int)$pr['vendor_part_id']] = [
                'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
                'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
            ];
        }
        return $out;
    }

    /** Hydrate a row array with pack info before constructing VendorPart. */
    private function hydratePacks(array $row): array {
        $packs = $this->loadPacksByVpId([(int)$row['id']]);
        $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
        $row['fullPackQuantity'] = $p['minPack']; // back-compat with callers
        $row['packQuantities']  = $p['packs'];
        return $row;
    }

    public function getById(int $id): ?VendorPart {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE vp.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new VendorPart($this->hydratePacks($row));
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE vp.isActive = 1" : "";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByVendor(int $vendorId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.vendor_id = ? AND vp.isActive = 1"
            : "WHERE vp.vendor_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByProducer(int $producerId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.producer_id = ? AND vp.isActive = 1"
            : "WHERE vp.producer_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$producerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByPart(int $partsId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.parts_id = ? AND vp.isActive = 1"
            : "WHERE vp.parts_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$partsId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    /**
     * Insert a new VendorPart row. Pack sizes are NOT inserted here — the
     * admin UI / import script that calls create() is responsible for
     * inserting into list__vendor_part_pack. The repository stays a thin
     * wrapper so call sites that don't need pack data don't pay for it.
     *
     * The $fullPackQuantity parameter is kept for back-compat (admin
     * "Pełne opakowanie" form); when present and positive, a single pack
     * row is created in addition to the header row.
     */
    public function create(
        int $vendorId,
        int $producerId,
        int $partsId,
        string $vendorPartNo,
        int $vendorJmId,
        float $fullPackQuantity = 1,
        ?string $comment = null,
        ?string $producerPartNo = null
    ): int {
        $MsaDB = $this->MsaDB;
        if ($vendorId   <= 0) throw new \InvalidArgumentException("VendorPart vendorId must be positive.");
        if ($producerId <= 0) throw new \InvalidArgumentException("VendorPart producerId must be positive.");
        if ($partsId    <= 0) throw new \InvalidArgumentException("VendorPart partsId must be positive.");
        if ($vendorJmId <= 0) throw new \InvalidArgumentException("VendorPart vendorJmId must be positive.");
        if ($fullPackQuantity <= 0) throw new \InvalidArgumentException("VendorPart fullPackQuantity must be positive.");
        $vendorPartNo = trim($vendorPartNo);
        if ($vendorPartNo === '') throw new \InvalidArgumentException("VendorPart vendorPartNo cannot be empty.");
        $producerPartNo = ($producerPartNo === null || $producerPartNo === '')
            ? null : trim($producerPartNo);

        $newId = $MsaDB->insert(
            'list__vendor_part',
            ['vendor_id', 'producer_id', 'parts_id', 'vendor_part_no', 'producer_part_no', 'vendor_jm_id', 'isActive', 'comment'],
            [$vendorId, $producerId, $partsId, $vendorPartNo, $producerPartNo, $vendorJmId, 1, $comment]
        );
        $MsaDB->db->prepare(
            "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
        )->execute([$newId, $fullPackQuantity]);
        return $newId;
    }

    public function update(
        int $id,
        int $vendorId,
        int $producerId,
        int $partsId,
        string $vendorPartNo,
        int $vendorJmId,
        float $fullPackQuantity,
        ?string $comment,
        ?string $producerPartNo = null
    ): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorId   <= 0) throw new \InvalidArgumentException("VendorPart vendorId must be positive.");
        if ($producerId <= 0) throw new \InvalidArgumentException("VendorPart producerId must be positive.");
        if ($partsId    <= 0) throw new \InvalidArgumentException("VendorPart partsId must be positive.");
        if ($vendorJmId <= 0) throw new \InvalidArgumentException("VendorPart vendorJmId must be positive.");
        if ($fullPackQuantity <= 0) throw new \InvalidArgumentException("VendorPart fullPackQuantity must be positive.");
        $vendorPartNo = trim($vendorPartNo);
        if ($vendorPartNo === '') throw new \InvalidArgumentException("VendorPart vendorPartNo cannot be empty.");
        $producerPartNo = ($producerPartNo === null || $producerPartNo === '')
            ? null : trim($producerPartNo);

        $ok = $MsaDB->update(
            'list__vendor_part',
            [
                'vendor_id'        => $vendorId,
                'producer_id'      => $producerId,
                'parts_id'         => $partsId,
                'vendor_part_no'   => $vendorPartNo,
                'producer_part_no' => $producerPartNo,
                'vendor_jm_id'     => $vendorJmId,
                'comment'          => $comment,
            ],
            'id',
            $id
        );
        if ($ok) {
            // Refresh single pack size for back-compat with the admin
            // "Pełne opakowanie" form. DELETE+INSERT collapses to a single
            // pack row (admin UI doesn't expose multi-pack yet).
            $MsaDB->db->prepare("DELETE FROM `list__vendor_part_pack` WHERE vendor_part_id = ?")
                     ->execute([$id]);
            $MsaDB->db->prepare(
                "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
            )->execute([$id, $fullPackQuantity]);
        }
        return $ok;
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__vendor_part',
            ['isActive' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function existsForVendorAndPartNo(int $vendorId, string $vendorPartNo): bool {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT id FROM `list__vendor_part` WHERE vendor_id = ? AND vendor_part_no = ? LIMIT 1");
        $stmt->execute([$vendorId, $vendorPartNo]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }
}
