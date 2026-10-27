<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?Vendor {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT v.id,
                       v.name,
                       v.address,
                       v.additional_data AS additionalData,
                       v.shop_url AS shopUrl,
                       v.lead_time_days AS leadTimeDays,
                       v.default_currency AS defaultCurrency,
                       c.code AS defaultCurrencyCode,
                       v.isActive,
                       v.comment,
                       v.preferred_email_draft_rfq_id AS preferredEmailDraftRfqId,
                       v.preferred_email_draft_po_id  AS preferredEmailDraftPoId,
                       v.created_at AS createdAt,
                       v.updated_at AS updatedAt
                FROM `list__vendor` v
                LEFT JOIN `list__currency` c ON c.id = v.default_currency
                WHERE v.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Vendor($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE v.isActive = 1" : "";
        $sql = "SELECT v.id,
                       v.name,
                       v.address,
                       v.additional_data AS additionalData,
                       v.shop_url AS shopUrl,
                       v.lead_time_days AS leadTimeDays,
                       v.default_currency AS defaultCurrency,
                       c.code AS defaultCurrencyCode,
                       v.isActive,
                       v.comment,
                       v.created_at AS createdAt,
                       v.updated_at AS updatedAt
                FROM `list__vendor` v
                LEFT JOIN `list__currency` c ON c.id = v.default_currency
                {$where}
                ORDER BY v.name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Vendor($row);
        }
        return $result;
    }

    /**
     * Create a new vendor row.
     *
     * @param string              $name
     * @param string|null         $address
     * @param string|null         $additionalData
     * @param int|null            $leadTimeDays
     * @param string|null         $comment
     * @param string              $defaultCurrencyCode  ISO 4217 code (defaults to 'PLN')
     * @param int|null            $preferredEmailDraftRfqId  FK → email_draft.id; 0 / null → NULL
     * @param int|null            $preferredEmailDraftPoId   FK → email_draft.id; 0 / null → NULL
     */
    public function create(string $name, ?string $address = null, ?string $additionalData = null, ?int $leadTimeDays = null, ?string $comment = null, string $defaultCurrencyCode = 'PLN', ?int $preferredEmailDraftRfqId = null, ?int $preferredEmailDraftPoId = null, ?string $shopUrl = null): int {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        $defaultCurrencyId = $this->resolveCurrencyId($defaultCurrencyCode);
        // 0 (from a stale form / "no selection" radio) → NULL so the
        // column reflects "no preferred draft" rather than a dangling
        // FK to row 0 (which doesn't exist and would break the FK
        // constraint on insert).
        $preferredEmailDraftRfqId = ($preferredEmailDraftRfqId === null || $preferredEmailDraftRfqId <= 0) ? null : $preferredEmailDraftRfqId;
        $preferredEmailDraftPoId  = ($preferredEmailDraftPoId  === null || $preferredEmailDraftPoId  <= 0) ? null : $preferredEmailDraftPoId;
        return $MsaDB->insert(
            'list__vendor',
            ['name', 'address', 'additional_data', 'shop_url', 'lead_time_days', 'default_currency', 'isActive', 'comment', 'preferred_email_draft_rfq_id', 'preferred_email_draft_po_id'],
            [$name, $address, $additionalData, $shopUrl, $leadTimeDays, $defaultCurrencyId, 1, $comment, $preferredEmailDraftRfqId, $preferredEmailDraftPoId]
        );
    }

    /**
     * Update an existing vendor row.
     *
     * @param int $preferredEmailDraftRfqId  0 / null → NULL (clear the preference)
     * @param int $preferredEmailDraftPoId   0 / null → NULL (clear the preference)
     */
    public function update(int $id, string $name, ?string $address, ?string $additionalData, ?int $leadTimeDays, ?string $comment, string $defaultCurrencyCode = 'PLN', ?int $preferredEmailDraftRfqId = null, ?int $preferredEmailDraftPoId = null, ?string $shopUrl = null): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        $defaultCurrencyId = $this->resolveCurrencyId($defaultCurrencyCode);
        // 0 (from a "no selection" / cleared picker) → NULL. Same
        // rationale as create(): a 0 would violate the FK constraint
        // (no email_draft with id = 0) and short-circuit the wizard's
        // NULL → fallback path.
        $preferredEmailDraftRfqId = ($preferredEmailDraftRfqId === null || $preferredEmailDraftRfqId <= 0) ? null : $preferredEmailDraftRfqId;
        $preferredEmailDraftPoId  = ($preferredEmailDraftPoId  === null || $preferredEmailDraftPoId  <= 0) ? null : $preferredEmailDraftPoId;
        return $MsaDB->update(
            'list__vendor',
            [
                'name' => $name,
                'address' => $address,
                'additional_data' => $additionalData,
                'shop_url' => $shopUrl,
                'lead_time_days' => $leadTimeDays,
                'default_currency' => $defaultCurrencyId,
                'comment' => $comment,
                'preferred_email_draft_rfq_id' => $preferredEmailDraftRfqId,
                'preferred_email_draft_po_id'  => $preferredEmailDraftPoId,
            ],
            'id',
            $id
        );
    }

    /**
     * Return the lightweight id+name list of every active email
     * draft, for the vendor edit page's `<select>` picker.
     *
     * Delegates to EmailDraftRepository so the catalogue lives in one
     * place — this method exists only because the vendor edit page
     * is rendered server-side (PHP) and the repository needs a
     * convenient hook for "give me the picker options".
     *
     * @return array<int, \stdClass> Each: {id:int, name:string}
     */
    public function getActiveEmailDrafts(): array {
        $repo = new EmailDraftRepository($this->MsaDB);
        return $repo->getActiveIdNameList();
    }

    /**
     * Resolve the preferred email draft (subject + body) for a vendor
     * + document type combo, or null when no preference is set / the
     * referenced draft was deactivated.
     *
     * Used by documents-send-ajax-load.php to enrich the wizard's
     * step 4 snapshot with the template the JS side will then run
     * through applyTemplate() (the same parser used by the live
     * preview on the email-drafts editor page).
     *
     * One SELECT joins `email_draft` to `list__vendor` by matching
     * either the RFQ FK column or the PO FK column against the
     * requested document type. The OR-condition is gated by the
     * :type parameter, so only the relevant column is considered per
     * call. `is_active = 1` ensures deactivated drafts don't leak into
     * outgoing emails even if the vendor FK still points at them.
     *
     * @param string $docType  'rfq' | 'po'
     * @return array{subject: string, body: string}|null
     */
    public function resolvePreferredDraft(int $vendorId, string $docType): ?array {
        if (!in_array($docType, ['rfq', 'po'], true)) {
            throw new \InvalidArgumentException("docType must be 'rfq' or 'po', got '{$docType}'.");
        }
        $MsaDB = $this->MsaDB;
        $sql = "SELECT ed.subject AS subject,
                       ed.body    AS body
                  FROM `email_draft` ed
                  JOIN `list__vendor` v ON (
                       (v.preferred_email_draft_rfq_id = ed.id AND :type = 'rfq')
                    OR (v.preferred_email_draft_po_id  = ed.id AND :type = 'po')
                  )
                 WHERE v.id = :vendorId
                   AND ed.is_active = 1
                 LIMIT 1";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([':type' => $docType, ':vendorId' => $vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'subject' => (string)$row['subject'],
            'body'    => (string)$row['body'],
        ];
    }

    /**
     * Resolve a currency CODE (3-letter ISO) to the integer id used by
     * `list__vendor.default_currency` (FK → list__currency.id). Empty
     * input falls back to 'PLN'. Throws when the code doesn't match
     * any active row — the calling form should validate before submit.
     */
    private function resolveCurrencyId(string $code): int {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $code = 'PLN';
        }
        $stmt = $this->MsaDB->db->prepare(
            "SELECT id FROM `list__currency` WHERE code = ? AND isActive = 1"
        );
        $stmt->execute([$code]);
        $id = (int)$stmt->fetchColumn();
        if ($id === 0) {
            throw new \InvalidArgumentException("Unknown or inactive currency code: '{$code}'.");
        }
        return $id;
    }

    /**
     * Normalise a user-entered shop URL: '' → null, scheme-less input
     * gets https://, only http/https and max 500 chars are accepted.
     *
     * @throws \InvalidArgumentException (Polish message, shown to the user)
     */
    public static function normalizeShopUrl(?string $raw): ?string {
        $url = trim((string)$raw);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (mb_strlen($url) > 500
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy adres sklepu (URL).');
        }
        return $url;
    }

    /** Update only the shop URL (inline edit from the cart). */
    public function updateShopUrl(int $id, ?string $shopUrl): bool {
        $stmt = $this->MsaDB->db->prepare("UPDATE `list__vendor` SET `shop_url` = ? WHERE `id` = ?");
        $stmt->execute([$shopUrl, $id]);
        return $stmt->rowCount() > 0;
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__vendor',
            ['isActive' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function countVendorParts(int $vendorId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_part` WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    public function countSuppliers(int $vendorId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_supplier` WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    /**
     * Build a parameterised WHERE clause from the vendor filter map,
     * shared by the COUNT and SELECT queries in search() so pagination
     * stays consistent with the active filters.
     *
     * Supported keys:
     *   status       'all' | 'active' | 'inactive'
     *   search       string -> name LIKE OR address LIKE OR comment LIKE
     *   hasArticles  bool   -> vendorPartCount > 0 (true) / = 0 (false)
     *   hasSuppliers bool   -> supplierCount   > 0 (true) / = 0 (false)
     *   dateFrom     YYYY-MM-DD -> created_at >=
     *   dateTo       YYYY-MM-DD -> created_at <=
     *
     * The supplierCount and vendorPartCount subqueries are inlined
     * directly into the WHERE so the same predicate works whether
     * the FROM is just `list__vendor v` (COUNT) or also aliases the
     * subqueries as selectable columns (SELECT).
     *
     * @return array{0: string, 1: array<int,mixed>} [whereSql, params]
     */
    private function buildSearchWhere(array $filters): array {
        $conds = [];
        $params = [];

        $status = $filters['status'] ?? 'all';
        if ($status === 'active')   { $conds[] = 'v.isActive = 1'; }
        elseif ($status === 'inactive') { $conds[] = 'v.isActive = 0'; }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            // OR-match against name, address, comment. Same $like for
            // all three — they search for the same substring.
            $conds[] = '(v.name LIKE ? OR v.address LIKE ? OR v.comment LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // hasArticles: vendorPartCount > 0 / = 0
        if (array_key_exists('hasArticles', $filters)) {
            $ha = $filters['hasArticles'];
            $vpSub = "(SELECT COUNT(*) FROM `list__vendor_part` WHERE vendor_id = v.id)";
            if ($ha === true)  { $conds[] = "{$vpSub} > 0"; }
            elseif ($ha === false) { $conds[] = "{$vpSub} = 0"; }
        }

        // hasSuppliers: supplierCount > 0 / = 0
        if (array_key_exists('hasSuppliers', $filters)) {
            $hs = $filters['hasSuppliers'];
            $spSub = "(SELECT COUNT(*) FROM `list__vendor_supplier` WHERE vendor_id = v.id)";
            if ($hs === true)  { $conds[] = "{$spSub} > 0"; }
            elseif ($hs === false) { $conds[] = "{$spSub} = 0"; }
        }

        $dateFrom = trim((string)($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $conds[]  = 'v.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = trim((string)($filters['dateTo'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $conds[]  = 'v.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereSql = empty($conds) ? '' : 'WHERE ' . implode(' AND ', $conds);
        return [$whereSql, $params];
    }

    /**
     * Count vendors matching the filter map (same keys as search()).
     * Used by vendor-list.php to fill the stat tiles above the list.
     */
    public function count(array $filters): int {
        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $stmt = $this->MsaDB->db->prepare("SELECT COUNT(*) FROM `list__vendor` v {$whereSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Filtered + paginated search of vendors. Mirrors the vendor-parts
     * / producers pagination contract: returns the rows for the
     * current page plus the total count under the same filters, so
     * the client can render pagination without a second round-trip.
     *
     * Always orders by v.name ASC (alphabetical — the operator can
     * scan the catalogue quickly).
     *
     * `supplierCount` and `vendorPartCount` are hydrated on every row
     * so the listing can show "#Dostawców" and "#Artykułów" without
     * a second round-trip.
     *
     * @param array $filters     see buildSearchWhere() for keys
     * @param int   $page        1-indexed page number (clamped to >= 1)
     * @param int   $itemsPerPage (clamped to >= 1)
     * @return array{rows: Vendor[], total: int, page: int, itemsPerPage: int}
     */
    public function search(array $filters, int $page = 1, int $itemsPerPage = 25): array {
        $MsaDB = $this->MsaDB;
        $page        = max(1, $page);
        $itemsPerPage = max(1, $itemsPerPage);
        $offset      = ($page - 1) * $itemsPerPage;

        [$whereSql, $params] = $this->buildSearchWhere($filters);

        // COUNT(*) under the same filters. The FROM is just
        // `list__vendor v`; the WHERE's subqueries are resolved
        // inline by MySQL.
        $countSql = "SELECT COUNT(*) AS total FROM `list__vendor` v {$whereSql}";
        $countStmt = $MsaDB->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

        $rows = [];
        if ($total > 0 && $offset < $total) {
            // SELECT aliases both subqueries as real columns so the
            // listing JS can read them without a second round-trip.
            $spCount = "(SELECT COUNT(*) FROM `list__vendor_supplier` WHERE vendor_id = v.id) AS supplierCount";
            $vpCount = "(SELECT COUNT(*) FROM `list__vendor_part`    WHERE vendor_id = v.id) AS vendorPartCount";
            $pageSql = "SELECT v.id, v.name, v.address, v.additional_data AS additionalData, v.shop_url AS shopUrl,"
                     . " v.lead_time_days AS leadTimeDays, v.default_currency AS defaultCurrency,"
                     . " c.code AS defaultCurrencyCode,"
                     . " v.isActive, v.comment,"
                     . " v.created_at AS createdAt, v.updated_at AS updatedAt,"
                     . " {$spCount}, {$vpCount}"
                     . " FROM `list__vendor` v"
                     . " LEFT JOIN `list__currency` c ON c.id = v.default_currency"
                     . " {$whereSql}"
                     . " ORDER BY v.name ASC"
                     . " LIMIT {$itemsPerPage} OFFSET {$offset}";
            $pageStmt = $MsaDB->db->prepare($pageSql);
            $pageStmt->execute($params);
            foreach ($pageStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $row['supplierCount']   = (int)($row['supplierCount'] ?? 0);
                $row['vendorPartCount'] = (int)($row['vendorPartCount'] ?? 0);
                $rows[] = new Vendor($row);
            }
        }

        return [
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'itemsPerPage' => $itemsPerPage,
        ];
    }
}
