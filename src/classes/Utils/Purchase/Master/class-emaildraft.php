<?php
namespace Atte\Utils\Purchase\Master;

class EmailDraft {
    public ?int $id;
    public string $name;
    public string $subject;
    public string $body;
    public string $appliesTo;
    public bool $isActive;
    public string $createdAt;
    public string $updatedAt;

    /**
     * Hydrate from a snake_case row produced by
     * EmailDraftRepository SELECTs (which alias `is_active AS isActive`,
     * `applies_to AS appliesTo` and `created_at AS createdAt` /
     * `updated_at AS updatedAt`). All fields except `appliesTo` are
     * required — an email draft without subject / body is not a valid
     * catalogue entry. `appliesTo` defaults to 'both' when the column
     * is absent from the row (legacy / pre-migration callers), so
     * the wizard still surfaces the draft for both RFQ and PO.
     */
    public function __construct(array $row) {
        $this->id        = isset($row['id']) ? (int)$row['id'] : null;
        $this->name      = (string)$row['name'];
        $this->subject   = (string)$row['subject'];
        $this->body      = (string)$row['body'];
        $this->appliesTo = (string)($row['appliesTo'] ?? 'both');
        $this->isActive  = (bool)(int)($row['isActive'] ?? 0);
        $this->createdAt = (string)($row['createdAt'] ?? '');
        $this->updatedAt = (string)($row['updatedAt'] ?? '');
    }
}