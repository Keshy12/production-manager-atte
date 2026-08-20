<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class Producer {
    private $MsaDB;
    public int $id;
    public string $name;
    public bool $isActive;
    public ?string $comment;

    public function __construct(MsaDB $MsaDB, array $row){
        $this->MsaDB = $MsaDB;
        $this->id = (int)$row['id'];
        $this->name = (string)$row['name'];
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
    }
}
