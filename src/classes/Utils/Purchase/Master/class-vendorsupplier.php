<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorSupplier {
    private $MsaDB;
    public int $id;
    public int $vendorId;
    public string $name;
    public ?string $jobTitle;
    public ?string $phone;
    public ?string $email;
    public bool $isActive;
    public ?string $comment;

    public function __construct(MsaDB $MsaDB, array $row){
        $this->MsaDB = $MsaDB;
        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->name = (string)$row['name'];
        $this->jobTitle = $row['jobTitle'] ?? null;
        $this->phone = $row['phone'] ?? null;
        $this->email = $row['email'] ?? null;
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
    }
}
