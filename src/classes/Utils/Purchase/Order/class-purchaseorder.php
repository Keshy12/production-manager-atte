<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class PurchaseOrder {
    private $MsaDB;
    public int $id;
    public int $vendorId;
    public string $state;
    public ?string $poNumber;
    public ?string $vendorPoNumber;
    public ?int $convertedFromRfqId;
    public ?string $expectedDeliveryDate;
    public ?string $sentAt;
    public ?string $confirmedAt;
    public int $createdBy;
    public ?string $comment;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $vendorName;
    public ?string $rfqNumber;
    public ?string $convertedFromRfqNumber;

    public function __construct(MsaDB $MsaDB, array $row){
        $this->MsaDB = $MsaDB;
        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->state = (string)$row['state'];
        $this->poNumber = $row['poNumber'] ?? null;
        $this->vendorPoNumber = $row['vendorPoNumber'] ?? null;
        $this->convertedFromRfqId = isset($row['convertedFromRfqId']) && $row['convertedFromRfqId'] !== null ? (int)$row['convertedFromRfqId'] : null;
        $this->expectedDeliveryDate = $row['expectedDeliveryDate'] ?? null;
        $this->sentAt = $row['sentAt'] ?? null;
        $this->confirmedAt = $row['confirmedAt'] ?? null;
        $this->createdBy = (int)$row['createdBy'];
        $this->comment = $row['comment'] ?? null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->rfqNumber = $row['convertedFromRfqNumber'] ?? null;
        $this->convertedFromRfqNumber = $row['convertedFromRfqNumber'] ?? null;
    }
}
