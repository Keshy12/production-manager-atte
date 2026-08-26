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
                       vp.full_pack_quantity AS fullPackQuantity,
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

    public function getById(int $id): ?VendorPart {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE vp.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new VendorPart($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE vp.isActive = 1" : "";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
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
        $result = [];
        foreach ($rows as $row) {
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
        $result = [];
        foreach ($rows as $row) {
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
        $result = [];
        foreach ($rows as $row) {
            $result[] = new VendorPart($row);
        }
        return $result;
    }

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

        return $MsaDB->insert(
            'list__vendor_part',
            ['vendor_id', 'producer_id', 'parts_id', 'vendor_part_no', 'producer_part_no', 'vendor_jm_id', 'full_pack_quantity', 'isActive', 'comment'],
            [$vendorId, $producerId, $partsId, $vendorPartNo, $producerPartNo, $vendorJmId, $fullPackQuantity, 1, $comment]
        );
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

        return $MsaDB->update(
            'list__vendor_part',
            [
                'vendor_id'          => $vendorId,
                'producer_id'        => $producerId,
                'parts_id'           => $partsId,
                'vendor_part_no'     => $vendorPartNo,
                'producer_part_no'   => $producerPartNo,
                'vendor_jm_id'       => $vendorJmId,
                'full_pack_quantity' => $fullPackQuantity,
                'comment'            => $comment,
            ],
            'id',
            $id
        );
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
