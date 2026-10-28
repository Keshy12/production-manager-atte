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
        // The two `is_default_*` booleans live in a separate pointer
        // table (`email_draft_default`, PK = doc_type), not on
        // `email_draft` itself. Re-derive them per row via LEFT JOIN
        // — safe because doc_type is a PK so each joined row is at
        // most one, no row multiplication. Aliasing the main table
        // as `e` is mandatory once we reference a join condition.
        $sql = "SELECT e.id, e.name, e.subject, e.body,
                       e.applies_to AS appliesTo,
                       e.is_active AS isActive,
                       IF(dr.draft_id = e.id, 1, 0) AS isDefaultRfq,
                       IF(dp.draft_id = e.id, 1, 0) AS isDefaultPo,
                       e.created_at AS createdAt,
                       e.updated_at AS updatedAt
                  FROM `email_draft` e
                  LEFT JOIN `email_draft_default` dr ON dr.doc_type = 'rfq'
                  LEFT JOIN `email_draft_default` dp ON dp.doc_type = 'po'
                 WHERE e.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new EmailDraft($row);
    }

    /**
     * Effective selection for ONE document-type picker on the vendor
     * card.
     *
     * Mirrors the send wizard's resolution chain
     * (documents-send.js → initSelectedDraftFromDoc):
     *   vendor preference → GLOBAL default for this type → first
     *   template in the catalogue → nothing.
     *
     * The catalogue is filtered per document type
     * (applies_to IN ('rfq','both') / IN ('po','both')) — the same
     * split the send wizard applies — so the picker only offers
     * templates the wizard will really use for that document type.
     * Offering a PO-only template as an RFQ preference would create
     * a silent mismatch: the wizard filters it out and falls back to
     * the default, while the vendor card would claim otherwise.
     *
     * `defaultId` = what the vendor gets when it has NO own
     * preference (the global default when usable, otherwise the first
     * catalogue entry). `selectedId` = what the picker must show (the
     * vendor's own preference when still usable, otherwise
     * `defaultId`).
     *
     * Static + pure on purpose: the vendor save endpoint reuses it to
     * tell an OWN preference (store it) from the default in disguise
     * (store NULL, so the vendor keeps following the global default).
     *
     * @param string $docType    'rfq' | 'po'
     * @param int    $prefId     FK → email_draft.id (0 = no preference)
     * @param array  $catalogue  Rows from getActiveIdNameList()
     *                           (objects or arrays with id / name /
     *                           appliesTo)
     * @param array  $defaultIds Rows from getDefaultDraftIds()
     * @return array{
     *     options: array<int, array{id:int, name:string, isGlobalDefault:bool}>,
     *     selectedId: int,
     *     defaultId: int
     * }
     */
    public static function resolvePickerState(
        string $docType,
        int $prefId,
        array $catalogue,
        array $defaultIds
    ): array {
        if (!in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException(
                "resolvePickerState(): docType must be one of: rfq, po "
                . "(passed: " . var_export($docType, true) . ")"
            );
        }
        $rawDefaultId = (int)($defaultIds[$docType] ?? 0);

        $options = [];
        foreach ($catalogue as $row) {
            if (is_array($row)) {
                $id        = (int)($row['id'] ?? 0);
                $name      = (string)($row['name'] ?? '');
                $appliesTo = (string)($row['appliesTo'] ?? ($row['applies_to'] ?? ''));
            } else {
                $id        = (int)($row->id ?? 0);
                $name      = (string)($row->name ?? '');
                $appliesTo = (string)($row->appliesTo ?? '');
            }
            if ($id <= 0) {
                continue;
            }
            if ($appliesTo !== $docType && $appliesTo !== 'both') {
                continue;
            }
            $options[] = [
                'id'              => $id,
                'name'            => $name,
                'isGlobalDefault' => ($id === $rawDefaultId),
            ];
        }

        $optionIds = array_column($options, 'id');
        $defaultId = in_array($rawDefaultId, $optionIds, true)
            ? $rawDefaultId
            : (int)($optionIds[0] ?? 0);

        $selectedId = ($prefId > 0 && in_array($prefId, $optionIds, true))
            ? $prefId
            : $defaultId;

        return [
            'options'    => $options,
            'selectedId' => $selectedId,
            'defaultId'  => $defaultId,
        ];
    }

    /**
     * @return EmailDraft[]
     */
    public function getAll(bool $activeOnly = false): array {
        $MsaDB = $this->MsaDB;
        $where = $activeOnly ? "WHERE e.is_active = 1" : "";
        // Same LEFT JOIN pattern as getById(): the two `is_default_*`
        // booleans live in `email_draft_default` (PK = doc_type).
        // Re-deriving per row keeps the EmailDraft entity API stable
        // while the storage shape changed underneath.
        $sql = "SELECT e.id, e.name, e.subject, e.body,
                       e.applies_to AS appliesTo,
                       e.is_active AS isActive,
                       IF(dr.draft_id = e.id, 1, 0) AS isDefaultRfq,
                       IF(dp.draft_id = e.id, 1, 0) AS isDefaultPo,
                       e.created_at AS createdAt,
                       e.updated_at AS updatedAt
                  FROM `email_draft` e
                  LEFT JOIN `email_draft_default` dr ON dr.doc_type = 'rfq'
                  LEFT JOIN `email_draft_default` dp ON dp.doc_type = 'po'
                  {$where}
                 ORDER BY e.name ASC, e.id ASC";
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
     * The projection carries three extra fields because the vendor
     * card needs them and cannot compute them on its own:
     *   - appliesTo    — which wizard(s) the draft may be used in.
     *                   The vendor picker is split per document type
     *                   exactly like the send wizard's catalogue
     *                   (applies_to IN ('rfq','both') for RFQ,
     *                   IN ('po','both') for PO), so a PO-only
     *                   template is never offered as an RFQ
     *                   preference — the wizard would ignore it.
     *   - isDefaultRfq / isDefaultPo — is this draft the GLOBAL
     *                   default for that document type? Derived from
     *                   the `email_draft_default` pointer table
     *                   (PK = doc_type → at most one joined row, so
     *                   no row multiplication).
     *
     * @return array<int, \stdClass> Each:
     *         {id:int, name:string, appliesTo:string,
     *          isDefaultRfq:bool, isDefaultPo:bool}
     */
    public function getActiveIdNameList(): array {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT e.id, e.name,
                       e.applies_to AS applies_to,
                       IF(dr.draft_id = e.id, 1, 0) AS is_default_rfq,
                       IF(dp.draft_id = e.id, 1, 0) AS is_default_po
                  FROM `email_draft` e
                  LEFT JOIN `email_draft_default` dr ON dr.doc_type = 'rfq'
                  LEFT JOIN `email_draft_default` dp ON dp.doc_type = 'po'
                 WHERE e.is_active = 1
                 ORDER BY e.name ASC, e.id ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $row              = new \stdClass();
            $row->id          = (int)$r['id'];
            $row->name        = (string)$r['name'];
            $row->appliesTo   = (string)$r['applies_to'];
            $row->isDefaultRfq = (bool)(int)$r['is_default_rfq'];
            $row->isDefaultPo  = (bool)(int)$r['is_default_po'];
            $out[]            = $row;
        }
        return $out;
    }

    /**
     * Ids of the two GLOBAL default drafts — one per document type.
     *
     * The pointer table `email_draft_default` has PK = doc_type, so
     * "one default per type" is a structural invariant: there can be
     * at most one row for 'rfq' and one for 'po'.
     *
     * The returned id is NOT filtered by is_active / applies_to — it
     * is the raw pointer target. Callers decide what to do with a
     * pointer that points at an inactive or out-of-scope draft (the
     * vendor card falls back to the first usable template, exactly
     * like the send wizard does).
     *
     * @return array{rfq:int, po:int} 0 when no pointer row exists for
     *         that document type.
     */
    public function getDefaultDraftIds(): array {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare(
            "SELECT `doc_type`, `draft_id` FROM `email_draft_default`"
        );
        $stmt->execute();
        $out = ['rfq' => 0, 'po' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $type = (string)$r['doc_type'];
            if (array_key_exists($type, $out)) {
                $out[$type] = (int)$r['draft_id'];
            }
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
     * @return array<int, array{id:int, name:string, subject:string, body:string, isDefaultRfq:bool, isDefaultPo:bool}>
     */
    public function getActiveForPicker(?string $docType = null): array {
        $MsaDB = $this->MsaDB;
        if ($docType !== null && !in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException(
                "docType musi być jednym z: rfq, po (przekazano: " . var_export($docType, true) . ")"
            );
        }
        // The two `is_default_*` booleans live in `email_draft_default`
        // (PK = doc_type). Re-derive per row via LEFT JOIN so the
        // returned array shape is unchanged from the pre-pointer
        // version — the consumer (documents-send-ajax-load.php →
        // documents-send.js) keeps reading `dr.isDefaultRfq` /
        // `dr.isDefaultPo` without any caller-side changes.
        if ($docType === null) {
            $sql = "SELECT e.id, e.name, e.subject, e.body,
                           IF(dr.draft_id = e.id, 1, 0) AS is_default_rfq,
                           IF(dp.draft_id = e.id, 1, 0) AS is_default_po
                      FROM `email_draft` e
                      LEFT JOIN `email_draft_default` dr ON dr.doc_type = 'rfq'
                      LEFT JOIN `email_draft_default` dp ON dp.doc_type = 'po'
                     WHERE e.is_active = 1
                     ORDER BY e.name ASC, e.id ASC";
            $params = [];
        } else {
            $sql = "SELECT e.id, e.name, e.subject, e.body,
                           IF(dr.draft_id = e.id, 1, 0) AS is_default_rfq,
                           IF(dp.draft_id = e.id, 1, 0) AS is_default_po
                      FROM `email_draft` e
                      LEFT JOIN `email_draft_default` dr ON dr.doc_type = 'rfq'
                      LEFT JOIN `email_draft_default` dp ON dp.doc_type = 'po'
                     WHERE e.is_active = 1
                       AND (e.applies_to = :docType OR e.applies_to = 'both')
                     ORDER BY e.name ASC, e.id ASC";
            $params = [':docType' => $docType];
        }
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'name'         => (string)$r['name'],
                'subject'      => (string)$r['subject'],
                'body'         => $r['body'] === null ? '' : (string)$r['body'],
                'isDefaultRfq' => (bool)(int)$r['is_default_rfq'],
                'isDefaultPo'  => (bool)(int)$r['is_default_po'],
            ];
        }
        return $out;
    }

    /**
     * Single-default-per-type is enforced STRUCTURALLY via the
     * `email_draft_default` pointer table (PK = doc_type), so the
     * repository doesn't need to coordinate a clear-then-Update dance
     * on sibling rows: there physically cannot be two pointer rows
     * for the same type. Flag $isDefaultRfq = true upserts a pointer
     * row keyed 'rfq' pointing at the new draft's id; flag = false
     * deletes the 'rfq' pointer row (if any). The same applies
     * independently to $isDefaultPo, so unchecking the RFQ default
     * never disturbs the PO pointer row and vice versa.
     *
     * @param  string $appliesTo  'rfq' | 'po' | 'both' — which wizard(s)
     *                            should surface this draft.
     * @param  bool   $isDefaultRfq  Mark this draft as the global default
     *                               for RFQ (lower priority than
     *                               list__vendor.preferred_email_draft_rfq_id).
     * @param  bool   $isDefaultPo   Mark this draft as the global default
     *                               for PO (lower priority than
     *                               list__vendor.preferred_email_draft_po_id).
     * @return int The new draft's id.
     */
    public function create(
        string $name,
        string $subject,
        string $body,
        bool $isActive,
        string $appliesTo = 'both',
        bool $isDefaultRfq = false,
        bool $isDefaultPo = false
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
        $newId = $MsaDB->insert(
            'email_draft',
            ['name', 'subject', 'body', 'applies_to', 'is_active', 'created_at', 'updated_at'],
            [$name, $subject, $body, $appliesTo, $isActive ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );
        // Promote-side invariant via the pointer table: structurally
        // only one row per doc_type, so no sibling-clearing is needed.
        // For CREATE the new row has no pointer of its own yet, so
        // isDefault* = false is a no-op (we must not delete a pointer
        // that points at a DIFFERENT draft — the create() call has
        // no business touching it). Independence between RFQ and PO
        // is maintained by handling each pointer write separately.
        if ($isDefaultRfq) {
            $this->upsertDefaultPointer('rfq', $newId);
        }
        if ($isDefaultPo) {
            $this->upsertDefaultPointer('po', $newId);
        }
        return $newId;
    }

    /**
     * Updates name / subject / body / applies_to / is_active in one
     * shot and bumps updated_at to NOW(). Caller is responsible for
     * guarding against drafts that are still referenced by
     * list__vendor — this repo does not enforce that (the UI surfaces
     * the list of vendors using each draft before allowing a
     * destructive edit).
     *
     * The two `is_default_*` booleans live in `email_draft_default`
     * (PK = doc_type), NOT on `email_draft`. Setting $isDefaultRfq =
     * true upserts a pointer row keyed 'rfq' pointing at $id; setting
     * it to false deletes the 'rfq' pointer row (if any). Same
     * independently for $isDefaultPo. Because the pointer table's PK
     * makes "one default per type" a structural invariant, the
     * previous "clear siblings, then promote" two-step is no longer
     * needed.
     *
     * @param  bool $isDefaultRfq  Mark this draft as the global default
     *                             for RFQ.
     * @param  bool $isDefaultPo   Mark this draft as the global default
     *                             for PO.
     */
    public function update(
        int $id,
        string $name,
        string $subject,
        string $body,
        bool $isActive,
        string $appliesTo = 'both',
        bool $isDefaultRfq = false,
        bool $isDefaultPo = false
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
        $ok = $MsaDB->update(
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
        // Same pointer-row pattern as create(), but the unset path
        // is conditional: an UPDATE with isDefaultRfq = false means
        // "this draft is no longer the RFQ default", which we honour
        // by deleting the pointer IFF it currently points at this
        // draft. If the pointer points at some OTHER draft we leave
        // it alone — the caller is just saying "this draft isn't
        // the default", not "clear the global RFQ default". This
        // keeps the two flags independent: unsetting RFQ never
        // disturbs the PO pointer, and unsetting a flag that wasn't
        // pointing at this row is a safe no-op.
        if ($isDefaultRfq) {
            $this->upsertDefaultPointer('rfq', $id);
        } else {
            $this->deleteDefaultPointerIfTarget('rfq', $id);
        }
        if ($isDefaultPo) {
            $this->upsertDefaultPointer('po', $id);
        } else {
            $this->deleteDefaultPointerIfTarget('po', $id);
        }
        return $ok;
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
     * `..._po_id`). This is the *vendor-level* default — a higher
     * priority than the global default held by the
     * `email_draft_default` pointer table — so the drafts list shows it
     * as a count next to the "Domyślny" column: a draft can be nobody's
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
     * Inactive vendors are counted too — the FK is still there and the
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
     * guarantees there is no sibling row to clear — uniqueness is
     * structural, not application-layer.
     *
     * Whitelist guard is a real safety requirement: $docType is
     * bound via PDO parameter, so this is mostly belt-and-braces,
     * but it gives the caller a clear error if they mistype a
     * doc-type instead of silently inserting a junk pointer row.
     *
     * @param  string $docType  'rfq' | 'po'
     * @param  int    $draftId  Target draft id (must exist in
     *                          `email_draft` — FK enforces this).
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
     * points at $draftId. Called from update() with flag=false —
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