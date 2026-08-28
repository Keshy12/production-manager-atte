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
        $sql = "SELECT id,
                       name,
                       address,
                       additional_data AS additionalData,
                       lead_time_days AS leadTimeDays,
                       isActive,
                       comment,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                FROM `list__vendor`
                WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Vendor($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE isActive = 1" : "";
        $sql = "SELECT id,
                       name,
                       address,
                       additional_data AS additionalData,
                       lead_time_days AS leadTimeDays,
                       isActive,
                       comment,
                       created_at AS createdAt,
                       updated_at AS updatedAt
                FROM `list__vendor`
                {$where}
                ORDER BY name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Vendor($row);
        }
        return $result;
    }

    public function create(string $name, ?string $address = null, ?string $additionalData = null, ?int $leadTimeDays = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        return $MsaDB->insert(
            'list__vendor',
            ['name', 'address', 'additional_data', 'lead_time_days', 'isActive', 'comment'],
            [$name, $address, $additionalData, $leadTimeDays, 1, $comment]
        );
    }

    public function update(int $id, string $name, ?string $address, ?string $additionalData, ?int $leadTimeDays, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Vendor name cannot be empty.");
        }
        return $MsaDB->update(
            'list__vendor',
            [
                'name' => $name,
                'address' => $address,
                'additional_data' => $additionalData,
                'lead_time_days' => $leadTimeDays,
                'comment' => $comment,
            ],
            'id',
            $id
        );
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
            $pageSql = "SELECT v.id, v.name, v.address, v.additional_data AS additionalData,"
                     . " v.lead_time_days AS leadTimeDays, v.isActive, v.comment,"
                     . " v.created_at AS createdAt, v.updated_at AS updatedAt,"
                     . " {$spCount}, {$vpCount}"
                     . " FROM `list__vendor` v"
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
