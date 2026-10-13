<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class ProducerRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function getById(int $id): ?Producer {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name, isActive, comment
                FROM `list__producer`
                WHERE id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Producer($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE isActive = 1" : "";
        $sql = "SELECT id, name, isActive, comment
                FROM `list__producer`
                {$where}
                ORDER BY name ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Producer($row);
        }
        return $result;
    }

    public function getByName(string $name): ?Producer {
        $MsaDB = $this->MsaDB;
        $sql = "SELECT id, name, isActive, comment
                FROM `list__producer`
                WHERE name = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Producer($row);
    }

    public function create(string $name, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Producer name cannot be empty.");
        }
        return $MsaDB->insert(
            'list__producer',
            ['name', 'isActive', 'comment'],
            [$name, 1, $comment]
        );
    }

    public function update(int $id, string $name, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException("Producer name cannot be empty.");
        }
        return $MsaDB->update(
            'list__producer',
            [
                'name' => $name,
                'comment' => $comment,
            ],
            'id',
            $id
        );
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__producer',
            ['isActive' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function countVendorParts(int $producerId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `list__vendor_part` WHERE producer_id = ?");
        $stmt->execute([$producerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    /**
     * Build a parameterised WHERE clause from the producer filter map,
     * shared by the COUNT and SELECT queries in search() so pagination
     * stays consistent with the active filters.
     *
     * Supported keys:
     *   status      'all' | 'active' | 'inactive'
     *   search      string -> name LIKE (case-insensitive) OR comment LIKE
     *   hasArticles bool   -> true  → only producers with vendorPartCount > 0
     *                         false → only producers with vendorPartCount = 0
     *                         null  → ignored
     *
     * @return array{0: string, 1: array<int,mixed>} [whereSql, params]
     */
    private function buildSearchWhere(array $filters): array {
        $conds = [];
        $params = [];

        $status = $filters['status'] ?? 'all';
        if ($status === 'active')   { $conds[] = 'p.isActive = 1'; }
        elseif ($status === 'inactive') { $conds[] = 'p.isActive = 0'; }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            // Match against name + comment. Same $like for both — they
            // search for the same substring.
            $conds[] = '(p.name LIKE ? OR p.comment LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        // hasArticles: inline the count subquery directly into the WHERE
        // so the predicate is portable between COUNT and SELECT — the
        // COUNT query uses just `list__producer p` as the FROM (no
        // aliasable vendorPartCount column), while the SELECT aliases
        // the same subquery as a real column for the listing JS.
        if (array_key_exists('hasArticles', $filters)) {
            $ha = $filters['hasArticles'];
            $vpSub = "(SELECT COUNT(*) FROM `list__vendor_part` WHERE producer_id = p.id)";
            if ($ha === true)  { $conds[] = "{$vpSub} > 0"; }
            elseif ($ha === false) { $conds[] = "{$vpSub} = 0"; }
        }

        $whereSql = empty($conds) ? '' : 'WHERE ' . implode(' AND ', $conds);
        return [$whereSql, $params];
    }

    /**
     * Filtered + paginated search of producers. Mirrors the vendor-parts
     * pagination contract: returns the rows for the current page plus the
     * total count under the same filters, so the client can render
     * pagination without a second round-trip.
     *
     * Always orders by p.name ASC (per spec — alphabetical so the
     * operator can scan the catalogue quickly).
     *
     * `vendorPartCount` is hydrated on every row so the listing can show
     * "Liczba artykułów" without a second round-trip per page.
     *
     * @param array $filters     see buildSearchWhere() for keys
     * @param int   $page        1-indexed page number (clamped to >= 1)
     * @param int   $itemsPerPage (clamped to >= 1)
     * @return array{rows: Producer[], total: int, page: int, itemsPerPage: int}
     */
    public function search(array $filters, int $page = 1, int $itemsPerPage = 25): array {
        $MsaDB = $this->MsaDB;
        $page        = max(1, $page);
        $itemsPerPage = max(1, $itemsPerPage);
        $offset      = ($page - 1) * $itemsPerPage;

        // Subquery for downstream-usage count. Aliased once for the
        // SELECT so the listing JS can read it as a real column;
        // buildSearchWhere() inlines the same expression into WHERE.
        $vpCount = "(SELECT COUNT(*) FROM `list__vendor_part` WHERE producer_id = p.id) AS vendorPartCount";
        $baseSelect = "SELECT p.id, p.name, p.isActive, p.comment, {$vpCount} FROM `list__producer` p";

        [$whereSql, $params] = $this->buildSearchWhere($filters);

        // COUNT(*) under the same filters — separate statement, prepared
        // with the same params so the predicate stays consistent. The
        // FROM is just `list__producer p`; the WHERE's vendorPartCount
        // subquery is resolved inline by MySQL.
        $countSql = "SELECT COUNT(*) AS total FROM `list__producer` p {$whereSql}";
        $countStmt = $MsaDB->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

        $rows = [];
        if ($total > 0 && $offset < $total) {
            $pageSql = "{$baseSelect} {$whereSql}"
                     . " ORDER BY p.name ASC"
                     . " LIMIT {$itemsPerPage} OFFSET {$offset}";
            $pageStmt = $MsaDB->db->prepare($pageSql);
            $pageStmt->execute($params);
            foreach ($pageStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                // Hydrate vendorPartCount onto the row before constructing
                // the DTO so the view layer can read it directly.
                $row['vendorPartCount'] = (int)($row['vendorPartCount'] ?? 0);
                $rows[] = new Producer($row);
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
