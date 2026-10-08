<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class EmailDraftRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?EmailDraft {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name, subject, body,
                       applies_to AS appliesTo,
                       is_active AS isActive,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                  FROM `email_draft`
                 WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new EmailDraft($row);
    }

    /**
     * @return EmailDraft[]
     */
    public function getAll(bool $activeOnly = false): array {
        $MsaDB = $this->MsaDB;
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        $sql = "SELECT id, name, subject, body,
                       applies_to AS appliesTo,
                       is_active AS isActive,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                  FROM `email_draft`
                  {$where}
                 ORDER BY name ASC, id ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new EmailDraft($row);
        }
        return $result;
    }

    /**
     * Returns a minimal id+name projection (no subject/body) so the
     * vendor edit page can populate its `<select>` picker without
     * shipping kilobytes of unused template text per option. Used by
     * VendorRepository::getActiveEmailDrafts() — kept here as the
     * canonical lightweight reader.
     *
     * @return array<int, \stdClass> Each: {id:int, name:string}
     */
    public function getActiveIdNameList(): array {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name
                  FROM `email_draft`
                 WHERE is_active = 1
                 ORDER BY name ASC, id ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $row        = new \stdClass();
            $row->id    = (int)$r['id'];
            $row->name  = (string)$r['name'];
            $out[]      = $row;
        }
        return $out;
    }

    /**
     * Full active-draft rows (id + name + subject + body) for the
     * "Szablon wiadomości" picker in step 4 of the send wizard
     * (/admin/purchase/documents/send). Unlike getActiveIdNameList()
     * this DOES ship subject+body: the wizard re-renders the composer's
     * subject input and body textarea the moment the operator changes
     * the picker, and a second AJAX roundtrip per change would make
     * that feel laggy. The catalogue is small (tens of rows), so the
     * extra payload is cheaper than the latency.
     *
     * When `$docType` is provided ('rfq' or 'po'), the result is
     * restricted to drafts whose `applies_to` matches the type OR is
     * 'both' — lets the operator carve out RFQ-only / PO-only templates
     * (e.g. a PO confirmation message that would look wrong on an RFQ).
     * Passing null keeps the legacy behaviour (return every active draft)
     * for any non-wizard caller that doesn't know the doc type.
     *
     * Returns plain arrays, not EmailDraft entities — the only consumer
     * is documents-send-ajax-load.php, which json_encodes them straight
     * into `doc.availableEmailDrafts`.
     *
     * @param  ?string $docType  'rfq' | 'po' | null (null = no filter)
     * @return array<int, array{id:int, name:string, subject:string, body:string}>
     */
    public function getActiveForPicker(?string $docType = null): array {
        $MsaDB = $this->MsaDB;
        if ($docType !== null && !in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException(
                "docType musi być jednym z: rfq, po (przekazano: " . var_export($docType, true) . ")"
            );
        }
        if ($docType === null) {
            $sql = "SELECT id, name, subject, body
                      FROM `email_draft`
                     WHERE is_active = 1
                     ORDER BY name ASC, id ASC";
            $params = [];
        } else {
            $sql = "SELECT id, name, subject, body
                      FROM `email_draft`
                     WHERE is_active = 1
                       AND (applies_to = :docType OR applies_to = 'both')
                     ORDER BY name ASC, id ASC";
            $params = [':docType' => $docType];
        }
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'      => (int)$r['id'],
                'name'    => (string)$r['name'],
                'subject' => (string)$r['subject'],
                'body'    => $r['body'] === null ? '' : (string)$r['body'],
            ];
        }
        return $out;
    }

    /**
     * @param  string $appliesTo  'rfq' | 'po' | 'both' — which wizard(s)
     *                            should surface this draft.
     * @return int The new draft's id.
     */
    public function create(
        string $name,
        string $subject,
        string $body,
        bool $isActive,
        string $appliesTo = 'both'
    ): int {
        $MsaDB = $this->MsaDB;
        $name    = trim($name);
        $subject = trim($subject);
        if ($name === '') {
            throw new \InvalidArgumentException("Nazwa szablonu nie może być pusta.");
        }
        if ($subject === '') {
            throw new \InvalidArgumentException("Temat szablonu nie może być pusty.");
        }
        if (!in_array($appliesTo, ['rfq', 'po', 'both'], true)) {
            throw new \InvalidArgumentException(
                "Typ szablonu musi być jednym z: rfq, po, both (przekazano: " . var_export($appliesTo, true) . ")"
            );
        }
        return $MsaDB->insert(
            'email_draft',
            ['name', 'subject', 'body', 'applies_to', 'is_active', 'created_at', 'updated_at'],
            [$name, $subject, $body, $appliesTo, $isActive ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );
    }

    /**
     * Updates name / subject / body / applies_to / is_active in one shot
     * and bumps updated_at to NOW(). Caller is responsible for guarding
     * against drafts that are still referenced by list__vendor — this
     * repo does not enforce that (the UI surfaces the list of vendors
     * using each draft before allowing a destructive edit).
     */
    public function update(
        int $id,
        string $name,
        string $subject,
        string $body,
        bool $isActive,
        string $appliesTo = 'both'
    ): bool {
        $MsaDB = $this->MsaDB;
        $name    = trim($name);
        $subject = trim($subject);
        if ($name === '') {
            throw new \InvalidArgumentException("Nazwa szablonu nie może być pusta.");
        }
        if ($subject === '') {
            throw new \InvalidArgumentException("Temat szablonu nie może być pusty.");
        }
        if (!in_array($appliesTo, ['rfq', 'po', 'both'], true)) {
            throw new \InvalidArgumentException(
                "Typ szablonu musi być jednym z: rfq, po, both (przekazano: " . var_export($appliesTo, true) . ")"
            );
        }
        return $MsaDB->update(
            'email_draft',
            [
                'name'       => $name,
                'subject'    => $subject,
                'body'       => $body,
                'applies_to' => $appliesTo,
                'is_active'  => $isActive ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id',
            $id
        );
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'email_draft',
            [
                'is_active'  => $isActive ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id',
            $id
        );
    }

    /**
     * How many vendors point at each draft through their own preference
     * FKs (`list__vendor.preferred_email_draft_rfq_id` /
     * `..._po_id`). This is the *vendor-level* default ΓÇö a higher
     * priority than the global default held by the
     * `email_draft_default` pointer table ΓÇö so the drafts list shows it
     * as a count next to the "Domy┼¢lny" column: a draft can be nobody's
     * global default yet still be the one half a dozen vendors actually
     * send, and that only shows up here.
     *
     * One grouped query instead of two per-draft counts: the OR in the
     * join is safe because the two counts are separate conditional
     * sums, so a vendor referencing the same draft for both types is
     * counted once in each and never double-counted inside one of them.
     * The vendor table is small (tens of rows), so the OR join costs
     * nothing measurable.
     *
     * Inactive vendors are counted too ΓÇö the FK is still there and the
     * number answers "how many vendors point here", not "how many send
     * mail today".
     *
     * @return array<int, array{rfqCount:int, poCount:int}> Keyed by
     *         draft id; drafts nobody points at are absent.
     */
    public function getVendorUsageCounts(): array {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT e.id AS draft_id,
                       SUM(CASE WHEN v.preferred_email_draft_rfq_id = e.id THEN 1 ELSE 0 END) AS rfq_count,
                       SUM(CASE WHEN v.preferred_email_draft_po_id  = e.id THEN 1 ELSE 0 END) AS po_count
                  FROM `email_draft` e
                  LEFT JOIN `list__vendor` v
                    ON v.preferred_email_draft_rfq_id = e.id
                    OR v.preferred_email_draft_po_id  = e.id
                 GROUP BY e.id";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['draft_id']] = [
                'rfqCount' => (int)$r['rfq_count'],
                'poCount'  => (int)$r['po_count'],
            ];
        }
        return $out;
    }

    /**
     * Upsert the pointer row for $docType to point at $draftId.
     * `ON DUPLICATE KEY UPDATE draft_id = VALUES(draft_id)` is the
     * rotate case (a new draft is now the default; the existing
     * pointer row is updated to point at it). The PK on doc_type
     * guarantees there is no sibling row to clear ΓÇö uniqueness is
     * structural, not application-layer.
     *
     * Whitelist guard is a real safety requirement: $docType is
     * bound via PDO parameter, so this is mostly belt-and-braces,
     * but it gives the caller a clear error if they mistype a
     * doc-type instead of silently inserting a junk pointer row.
     *
     * @param  string $docType  'rfq' | 'po'
     * @param  int    $draftId  Target draft id (must exist in
     *                          `email_draft` ΓÇö FK enforces this).
     * @return void
     * @throws \InvalidArgumentException if $docType is not whitelisted.
     */
    private function upsertDefaultPointer(string $docType, int $draftId): void {
        if (!in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException(
                "upsertDefaultPointer(): docType must be one of: rfq, po "
                . "(passed: " . var_export($docType, true) . ")"
            );
        }
        $stmt = $this->MsaDB->db->prepare(
            "INSERT INTO `email_draft_default` (`doc_type`, `draft_id`, `updated_at`)
                  VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `draft_id` = VALUES(`draft_id`), `updated_at` = NOW()"
        );
        $stmt->execute([$docType, $draftId]);
    }

    /**
     * Delete the pointer row for $docType, but ONLY IF it currently
     * points at $draftId. Called from update() with flag=false ΓÇö
     * "this draft is no longer the default for this type" is honoured
     * by removing a pointer that targets this row, and is a safe
     * no-op if the pointer targets a different row (so the caller
     * can't accidentally demote another draft's default by editing
     * the wrong one). Keeps the two flags independent: deleting the
     * 'rfq' pointer never touches the 'po' pointer row.
     *
     * Idempotent: a DELETE that matches no rows is a no-op (no error).
     *
     * @param  string $docType  'rfq' | 'po'
     * @param  int    $draftId  Row id to limit the removal to.
     * @return void
     * @throws \InvalidArgumentException if $docType is not whitelisted.
     */
    private function deleteDefaultPointerIfTarget(string $docType, int $draftId): void {
        if (!in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException(
                "deleteDefaultPointerIfTarget(): docType must be one of: rfq, po "
                . "(passed: " . var_export($docType, true) . ")"
            );
        }
        $stmt = $this->MsaDB->db->prepare(
            "DELETE FROM `email_draft_default` WHERE `doc_type` = ? AND `draft_id` = ?"
        );
        $stmt->execute([$docType, $draftId]);
    }
}