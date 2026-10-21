<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class Vendor {
        public int $id;
    public string $name;
    public ?string $address;
    public ?string $additionalData;
    // Vendor shop URL (http/https). Opened from the cart variants table.
    public ?string $shopUrl = null;
    public ?int $leadTimeDays;
    // FK → list__currency.id. Stored as int. Always > 0 for valid rows
    // (the column is NOT NULL and the FK enforces referential integrity).
    public int $defaultCurrency;
    // Joined string for display (e.g. "PLN"). Hydrated by the repository
    // when its SELECT joins list__currency; left null when the join is
    // absent (callers that only need the FK can ignore this).
    public ?string $defaultCurrencyCode = null;
    public bool $isActive;
    public ?string $comment;
    // FK → email_draft.id (the "preferred RFQ template" for this
    // vendor). NULL when the vendor has no preferred draft — the
    // /admin/purchase/documents/send step 4 wizard then falls back to
    // its hardcoded Polish default. Picked on the vendor edit page.
    public ?int $preferredEmailDraftRfqId;
    // FK → email_draft.id (the "preferred PO template" for this
    // vendor). Same NULL-fallback semantics as the RFQ column above.
    public ?int $preferredEmailDraftPoId;
    public ?string $createdAt;
    public ?string $updatedAt;
    // Hydrated by VendorRepository::search() so the listing can show
    // "#Dostawców" and "#Artykułów" without a second round-trip.
    // Not set by getById / getAll — callers needing these values
    // should call countSuppliers() / countVendorParts() explicitly.
    public int $supplierCount = 0;
    public int $vendorPartCount = 0;

    public function __construct(array $row){        $this->id = (int)$row['id'];
        $this->name = (string)$row['name'];
        $this->address = $row['address'] ?? null;
        $this->additionalData = $row['additionalData'] ?? null;
        $this->shopUrl = $row['shopUrl'] ?? null;
        $this->leadTimeDays = isset($row['leadTimeDays']) ? (int)$row['leadTimeDays'] : null;
        $this->defaultCurrency = isset($row['defaultCurrency']) ? (int)$row['defaultCurrency'] : 0;
        $this->defaultCurrencyCode = isset($row['defaultCurrencyCode']) ? (string)$row['defaultCurrencyCode'] : null;
        $this->isActive = (bool)(int)($row['isActive'] ?? 0);
        $this->comment = $row['comment'] ?? null;
        $this->preferredEmailDraftRfqId = isset($row['preferredEmailDraftRfqId']) ? (int)$row['preferredEmailDraftRfqId'] : null;
        $this->preferredEmailDraftPoId  = isset($row['preferredEmailDraftPoId'])  ? (int)$row['preferredEmailDraftPoId']  : null;
        $this->createdAt = $row['createdAt'] ?? null;
        $this->updatedAt = $row['updatedAt'] ?? null;
        $this->supplierCount = (int)($row['supplierCount'] ?? 0);
        $this->vendorPartCount = (int)($row['vendorPartCount'] ?? 0);
    }
}