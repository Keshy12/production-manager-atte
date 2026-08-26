<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorPart {
        public int $id;
    public int $vendorId;
    public int $producerId;
    public int $partsId;
    public string $vendorPartNo;
    public ?string $producerPartNo;
    public int $vendorJmId;
    /**
     * Smallest pack size (back-compat). The full list lives in
     * $packQuantities — multi-pack UI comes later.
     */
    public ?float $fullPackQuantity;
    /** @var float[] Pack sizes ascending; empty if vendor offers no packs. */
    public array $packQuantities = [];
    public bool $isActive;
    public ?string $comment;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $vendorName;
    public ?string $producerName;
    public ?string $partName;
    public ?string $unitName;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->producerId = (int)$row['producerId'];
        $this->partsId = (int)$row['partsId'];
        $this->vendorPartNo = (string)$row['vendorPartNo'];
        $this->producerPartNo = $row['producerPartNo'] ?? null;
        $this->vendorJmId = (int)$row['vendorJmId'];
        $this->fullPackQuantity = isset($row['fullPackQuantity']) && $row['fullPackQuantity'] !== null
            ? (float)$row['fullPackQuantity']
            : null;
        $this->packQuantities = $row['packQuantities'] ?? [];
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->producerName = $row['producerName'] ?? null;
        $this->partName = $row['partName'] ?? null;
        $this->unitName = $row['unitName'] ?? null;
    }
}
