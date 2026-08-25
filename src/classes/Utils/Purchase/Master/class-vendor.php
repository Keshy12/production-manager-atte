<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class Vendor {
        public int $id;
    public string $name;
    public ?string $address;
    public ?string $additionalData;
    public ?int $leadTimeDays;
    public bool $isActive;
    public ?string $comment;
    public ?string $createdAt;
    public ?string $updatedAt;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->name = (string)$row['name'];
        $this->address = $row['address'] ?? null;
        $this->additionalData = $row['additionalData'] ?? null;
        $this->leadTimeDays = isset($row['leadTimeDays']) ? (int)$row['leadTimeDays'] : null;
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
    }
}
