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
    public ?string $comment;
    public ?string $vendorName;
    public ?string $producerName;
    public ?string $partName;
    public ?string $unitName;
    public ?string $vendorPartNo;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->rfqId = (int)$row['rfqId'];
        $this->vendorPartId = (int)$row['vendorPartId'];
        $this->quantity = (float)$row['quantity'];
        $this->quantityUnitId = (int)$row['quantityUnitId'];
        $this->unitPrice = isset($row['unitPrice']) && $row['unitPrice'] !== null ? (float)$row['unitPrice'] : null;
        $this->currency = (string)($row['currency'] ?? 'PLN');
        $this->comment = $row['comment'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->producerName = $row['producerName'] ?? null;
        $this->partName = $row['partName'] ?? null;
        $this->unitName = $row['unitName'] ?? null;
        $this->vendorPartNo = $row['vendorPartNo'] ?? null;
    }
}
