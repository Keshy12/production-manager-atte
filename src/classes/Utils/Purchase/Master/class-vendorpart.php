<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorPart {
    private $MsaDB;
    public int $id;
    public int $vendorId;
    public int $producerId;
    public int $partsId;
    public string $vendorPartNo;
    public ?string $producerPartNo;
    public int $vendorJmId;
    public float $fullPackQuantity;
    public bool $isActive;
    public ?string $comment;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $vendorName;
    public ?string $producerName;
    public ?string $partName;
    public ?string $unitName;

    public function __construct(MsaDB $MsaDB, array $row){
        $this->MsaDB = $MsaDB;
        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->producerId = (int)$row['producerId'];
        $this->partsId = (int)$row['partsId'];
        $this->vendorPartNo = (string)$row['vendorPartNo'];
        $this->producerPartNo = $row['producerPartNo'] ?? null;
        $this->vendorJmId = (int)$row['vendorJmId'];
        $this->fullPackQuantity = (float)($row['fullPackQuantity'] ?? 1);
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
