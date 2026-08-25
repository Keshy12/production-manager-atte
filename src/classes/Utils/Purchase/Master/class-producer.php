<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class Producer {
        public int $id;
    public string $name;
    public bool $isActive;
    public ?string $comment;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->name = (string)$row['name'];
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
    }
}
