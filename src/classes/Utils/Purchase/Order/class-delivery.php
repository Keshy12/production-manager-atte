<?php
namespace Atte\Utils\Purchase\Order;

/**
 * Dostawa (jeden dokument dostawcy: faktura / WZ) — nagłówek tabeli
 * `purchase__delivery`. Czyste dane; logika w PurchaseActionHandler.
 *
 * Stany: draft (pozycje w trakcie wpisywania) → posted (towar przyjęty)
 *        → cancelled (zaksięgowana dostawa wycofana w całości).
 */
class Delivery {
    public int $id;
    public int $vendorId;
    public string $state;
    public string $documentType;
    public ?string $vendorDocumentNumber;
    public ?string $documentDate;
    public ?string $grnNumber;
    public string $currency;
    public ?int $defaultSubMagazineId;
    public ?string $receivedAt;
    public ?int $transferGroupId;
    public int $createdBy;
    public ?int $postedBy;
    public ?string $createdAt;
    public ?string $postedAt;
    public ?string $updatedAt;
    public ?string $comment;
    public ?string $vendorName;
    public ?string $createdByName;
    public ?string $postedByName;
    public ?string $defaultSubMagazineName;

    public function __construct(array $row) {
        $this->id = (int)$row['id'];
        $this->vendorId = (int)$row['vendorId'];
        $this->state = (string)($row['state'] ?? 'draft');
        $this->documentType = (string)($row['documentType'] ?? 'invoice');
        $this->vendorDocumentNumber = $row['vendorDocumentNumber'] ?? null;
        $this->documentDate = $row['documentDate'] ?? null;
        $this->grnNumber = $row['grnNumber'] ?? null;
        $this->currency = (string)($row['currency'] ?? 'PLN');
        $this->defaultSubMagazineId = isset($row['defaultSubMagazineId']) && $row['defaultSubMagazineId'] !== null
            ? (int)$row['defaultSubMagazineId'] : null;
        $this->receivedAt = $row['receivedAt'] ?? null;
        $this->transferGroupId = isset($row['transferGroupId']) && $row['transferGroupId'] !== null
            ? (int)$row['transferGroupId'] : null;
        $this->createdBy = (int)($row['createdBy'] ?? 0);
        $this->postedBy = isset($row['postedBy']) && $row['postedBy'] !== null ? (int)$row['postedBy'] : null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->postedAt = $row['postedAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
        $this->comment = $row['comment'] ?? null;
        $this->vendorName = $row['vendorName'] ?? null;
        $this->createdByName = $row['createdByName'] ?? null;
        $this->postedByName = $row['postedByName'] ?? null;
        $this->defaultSubMagazineName = $row['defaultSubMagazineName'] ?? null;
    }
}
