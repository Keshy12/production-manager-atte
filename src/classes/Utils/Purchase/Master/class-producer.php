<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class Producer {
        public int $id;
    public string $name;
    public bool $isActive;
    public ?string $comment;
    // Hydrated by ProducerRepository::search() so the listing can show
    // "Liczba artykułów" without a second round-trip. Not set by the
    // other read paths (getById/getAll/getByName) — callers needing
    // this value should call countVendorParts() explicitly.
    public int $vendorPartCount = 0;

    public function __construct(array $row){
        $this->id = (int)$row['id'];
        $this->name = (string)$row['name'];
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
        $this->vendorPartCount = (int)($row['vendorPartCount'] ?? 0);
    }
}
