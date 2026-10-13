<?php
namespace Atte\Utils\Purchase\Master;

class EmailDraft {
    public ?int $id;
    public string $name;
    public string $subject;
    public string $body;
    public string $appliesTo;
    public bool $isActive;
    public bool $isDefaultRfq;
    public bool $isDefaultPo;
    public string $createdAt;
    public string $updatedAt;

    /**
     * Hydrate from a snake_case row produced by
     * EmailDraftRepository SELECTs (which alias `is_active AS isActive`,
     * `applies_to AS appliesTo` and `created_at AS createdAt` /
     * `updated_at AS updatedAt`). The two `isDefaultRfq` / `isDefaultPo`
     * booleans are NOT columns — they come from `IF(...)` expressions
     * over a LEFT JOIN to the `email_draft_default` pointer table
     * (PK = doc_type), re-derived at SELECT-time per draft. SELECTs
     * alias them to `isDefaultRfq` / `isDefaultPo` so this entity
     * constructor stays camelCase like every other DTO.
     *
     * All fields except `appliesTo`, `isDefaultRfq` and `isDefaultPo`
     * are required — an email draft without subject / body is not a
     * valid catalogue entry. The three optional fields default to
     * sensible values (`'both'`, false, false) when absent from the
     * row, so legacy / pre-migration callers that don't supply them
     * still hydrate cleanly.
     */
    public function __construct(array $row) {
        $this->id           = isset($row['id']) ? (int)$row['id'] : null;
        $this->name         = (string)$row['name'];
        $this->subject      = (string)$row['subject'];
        $this->body         = (string)$row['body'];
        $this->appliesTo    = (string)($row['appliesTo'] ?? 'both');
        $this->isActive     = (bool)(int)($row['isActive'] ?? 0);
        $this->isDefaultRfq = (bool)(int)($row['isDefaultRfq'] ?? 0);
        $this->isDefaultPo  = (bool)(int)($row['isDefaultPo'] ?? 0);
        $this->createdAt    = (string)($row['createdAt'] ?? '');
        $this->updatedAt    = (string)($row['updatedAt'] ?? '');
    }
}