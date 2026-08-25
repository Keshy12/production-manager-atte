<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class RFQ {
        public int $id;
    public int $vendorId;
    public string $state;
    public ?string $rfqNumber;
    public ?string $expectedReplyDate;
    public ?string $sentAt;
    public int $createdBy;
    public ?string $comment;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $vendorName;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->state = (string)$row['state'];
        $this->rfqNumber = $row['rfqNumber'] ?? null;
        $this->expectedReplyDate = $row['expectedReplyDate'] ?? null;
        $this->sentAt = $row['sentAt'] ?? null;
        $this->createdBy = (int)$row['createdBy'];
        $this->comment = $row['comment'] ?? null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
    }
}
