<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class OrderReceipt {
    private $MsaDB;
    public int $id;
    public int $poId;
    public ?string $documentNumber;
    public int $receivedBy;
    public ?string $receivedAt;
    public ?string $comment;
    public ?string $poNumber;
    public ?string $vendorName;
    public ?string $receivedByName;

    public function __construct(MsaDB $MsaDB, array $row){
        $this->MsaDB = $MsaDB;
        $this->id = (int)$row['id'];
        $this->poId = (int)$row['poId'];
        $this->documentNumber = $row['documentNumber'] ?? null;
        $this->receivedBy = (int)$row['receivedBy'];
        $this->receivedAt = $row['receivedAt'] ?? null;
        $this->comment = $row['comment'] ?? null;
        $this->poNumber = $row['poNumber'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->receivedByName = $row['receivedByName'] ?? null;
    }
}
