<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class RFQItem {
        public int $id;
    public int $rfqId;
    public int $vendorPartId;
    public float $quantity;
    public int $quantityUnitId;
    public ?float $unitPrice;
    public string $currency;
    public ?float $pickedPackSize;
    public ?string $comment;
    public ?string $vendorName;
    public ?string $producerName;
    public ?string $partName;
    public ?string $partDescription;
    public ?string $unitName;
    public ?string $vendorPartNo;
    public ?string $producerPartNo;
    public ?string $vendorPartComment;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->rfqId = (int)$row['rfqId'];
        $this->vendorPartId = (int)$row['vendorPartId'];
        $this->quantity = (float)$row['quantity'];
        $this->quantityUnitId = (int)$row['quantityUnitId'];
        $this->unitPrice = isset($row['unitPrice']) && $row['unitPrice'] !== null ? (float)$row['unitPrice'] : null;
        $this->currency = (string)($row['currency'] ?? 'PLN');
        $this->pickedPackSize = isset($row['pickedPackSize']) && $row['pickedPackSize'] !== null
            ? (float)$row['pickedPackSize']
            : null;
        $this->comment = $row['comment'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->producerName = $row['producerName'] ?? null;
        $this->partName = $row['partName'] ?? null;
        $this->partDescription = $row['partDescription'] ?? null;
        $this->unitName = $row['unitName'] ?? null;
        $this->vendorPartNo = $row['vendorPartNo'] ?? null;
        $this->producerPartNo = $row['producerPartNo'] ?? null;
        $this->vendorPartComment = $row['vendorPartComment'] ?? null;
    }
}
