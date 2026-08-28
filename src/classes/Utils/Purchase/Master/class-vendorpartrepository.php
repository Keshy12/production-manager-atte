<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

class VendorPartRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT vp.id,
                       vp.vendor_id AS vendorId,
                       vp.producer_id AS producerId,
                       vp.parts_id AS partsId,
                       vp.vendor_part_no AS vendorPartNo,
                       vp.producer_part_no AS producerPartNo,
                       vp.vendor_jm_id AS vendorJmId,
                       vp.isActive,
                       vp.comment,
                       vp.created_at AS createdAt,
                       vp.updated_at AS updatedAt,
                       v.name AS vendorName,
                       p.name AS producerName,
                       lp.name AS partName,
                       u.name AS unitName
                FROM `list__vendor_part` vp
                LEFT JOIN `list__vendor` v   ON vp.vendor_id    = v.id
                LEFT JOIN `list__producer` p ON vp.producer_id  = p.id
                LEFT JOIN `list__parts` lp   ON vp.parts_id     = lp.id
                LEFT JOIN `part__unit` u     ON vp.vendor_jm_id = u.id";
    }

    /**
     * Pull pack sizes for the given vendor_part_ids in one query.
     * Returns [vpId => ['packs' => float[], 'minPack' => ?float]].
     */
    private function loadPacksByVpId(array $vpIds): array {
        if ($vpIds === []) return [];
        $MsaDB = $this->MsaDB;
        $placeholders = implode(',', array_fill(0, count($vpIds), '?'));
        $stmt = $MsaDB->db->prepare(
            "SELECT vendor_part_id,
                    MIN(full_pack_quantity) AS min_pack,
                    GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
               FROM `list__vendor_part_pack`
              WHERE vendor_part_id IN ($placeholders)
              GROUP BY vendor_part_id"
        );
        $stmt->execute($vpIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
            $out[(int)$pr['vendor_part_id']] = [
                'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
                'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
            ];
        }
        return $out;
    }

    /** Hydrate a row array with pack info before constructing VendorPart. */
    private function hydratePacks(array $row): array {
        $packs = $this->loadPacksByVpId([(int)$row['id']]);
        $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
        $row['fullPackQuantity'] = $p['minPack']; // back-compat with callers
        $row['packQuantities']  = $p['packs'];
        return $row;
    }

    public function getById(int $id): ?VendorPart {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE vp.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new VendorPart($this->hydratePacks($row));
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE vp.isActive = 1" : "";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByVendor(int $vendorId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.vendor_id = ? AND vp.isActive = 1"
            : "WHERE vp.vendor_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByProducer(int $producerId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.producer_id = ? AND vp.isActive = 1"
            : "WHERE vp.producer_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$producerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    public function getByPart(int $partsId, bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive
            ? "WHERE vp.parts_id = ? AND vp.isActive = 1"
            : "WHERE vp.parts_id = ?";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY vp.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$partsId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
            $row['fullPackQuantity'] = $p['minPack'];
            $row['packQuantities']  = $p['packs'];
            $result[] = new VendorPart($row);
        }
        return $result;
    }

    /**
     * Build a parameterised WHERE clause from the filter map used by both
     * the count query and the page query, so pagination stays consistent
     * with the active filters.
     *
* Supported keys:
     *     vendorIds    int[]  -> vp.vendor_id  IN (...)
     *     producerIds  int[]  -> vp.producer_id IN (...)
     *     partIds      int[]  -> vp.parts_id   IN (...)
     *     status       'all' | 'active' | 'inactive'
     *     search       string -> vp.vendor_part_no LIKE (case-insensitive)
     *   dateFrom     YYYY-MM-DD -> vp.created_at >=
     *   dateTo       YYYY-MM-DD -> vp.created_at <=
     *
     * @return array{0: string, 1: array<int,mixed>} [whereSql, params]
     */
    private function buildSearchWhere(array $filters): array {
        $conds = [];
        $params = [];

        $idList = function(array $ids): array {
            return array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        };

        if (!empty($filters['vendorIds'])) {
            $ids = $idList($filters['vendorIds']);
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conds[] = "vp.vendor_id IN ($placeholders)";
                $params  = array_merge($params, $ids);
            }
        }
        if (!empty($filters['producerIds'])) {
            $ids = $idList($filters['producerIds']);
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conds[] = "vp.producer_id IN ($placeholders)";
                $params  = array_merge($params, $ids);
            }
        }
        if (!empty($filters['partIds'])) {
            $ids = $idList($filters['partIds']);
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conds[] = "vp.parts_id IN ($placeholders)";
                $params  = array_merge($params, $ids);
            }
        }

        $status = $filters['status'] ?? 'all';
        if ($status === 'active')   { $conds[] = 'vp.isActive = 1'; }
        elseif ($status === 'inactive') { $conds[] = 'vp.isActive = 0'; }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            // OR-match against vendor part number, producer part number,
            // and producer name. Same $like for all three — they search
            // for the same substring. The COUNT query in search() joins
            // `list__producer` so `p.name` is available here.
            $conds[] = '(vp.vendor_part_no LIKE ? OR vp.producer_part_no LIKE ? OR p.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $dateFrom = trim((string)($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $conds[]  = 'vp.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = trim((string)($filters['dateTo'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $conds[]  = 'vp.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereSql = empty($conds) ? '' : 'WHERE ' . implode(' AND ', $conds);
        return [$whereSql, $params];
    }

    /**
     * Filtered + paginated search of vendor parts. Mirrors the archive
     * pagination contract: returns the rows for the current page plus the
     * total count under the same filters, so the client can render
     * pagination without a second round-trip.
     *
     * Always orders by vp.id DESC (per spec).
     *
     * @param array $filters     see buildSearchWhere() for keys
     * @param int   $page        1-indexed page number (clamped to >= 1)
     * @param int   $itemsPerPage (clamped to >= 1)
     * @return array{rows: VendorPart[], total: int, page: int, itemsPerPage: int}
     */
    public function search(array $filters, int $page = 1, int $itemsPerPage = 25): array {
        $MsaDB = $this->MsaDB;
        $page        = max(1, $page);
        $itemsPerPage = max(1, $itemsPerPage);
        $offset      = ($page - 1) * $itemsPerPage;

        [$whereSql, $params] = $this->buildSearchWhere($filters);

        // COUNT(*) under the same filters — separate statement, prepared
        // with the same params so the planner reuses the predicate. The
        // search predicate can reference `p.name` (producer name), so we
        // must include the producer join here even though it's not needed
        // for the other filters.
        $countSql = "SELECT COUNT(*) AS total FROM `list__vendor_part` vp "
                  . "LEFT JOIN `list__producer` p ON vp.producer_id = p.id {$whereSql}";
        $countStmt = $MsaDB->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

        $rows = [];
        if ($total > 0 && $offset < $total) {
            $pageSql = $this->buildSelectJoin()
                . " {$whereSql}"
                . " ORDER BY vp.id DESC"
                . " LIMIT {$itemsPerPage} OFFSET {$offset}";
            $pageStmt = $MsaDB->db->prepare($pageSql);
            $pageStmt->execute($params);
            $rawRows = $pageStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rawRows) {
                $packs = $this->loadPacksByVpId(array_map(fn($r) => (int)$r['id'], $rawRows));
                foreach ($rawRows as $row) {
                    $p = $packs[(int)$row['id']] ?? ['packs' => [], 'minPack' => null];
                    $row['fullPackQuantity'] = $p['minPack'];
                    $row['packQuantities']   = $p['packs'];
                    $rows[] = new VendorPart($row);
                }
            }
        }

        return [
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'itemsPerPage' => $itemsPerPage,
        ];
    }

    /**
     * Insert a new VendorPart row. Pack sizes are NOT inserted here — the
     * admin UI / import script that calls create() is responsible for
     * inserting into list__vendor_part_pack. The repository stays a thin
     * wrapper so call sites that don't need pack data don't pay for it.
     *
     * The $fullPackQuantity parameter is kept for back-compat (admin
     * "Pełne opakowanie" form); when present and positive, a single pack
     * row is created in addition to the header row.
     */
    public function create(
        int $vendorId,
        int $producerId,
        int $partsId,
        string $vendorPartNo,
        int $vendorJmId,
        float $fullPackQuantity = 1,
        ?string $comment = null,
        ?string $producerPartNo = null
    ): int {
        $MsaDB = $this->MsaDB;
        if ($vendorId   <= 0) throw new \InvalidArgumentException("VendorPart vendorId must be positive.");
        if ($producerId <= 0) throw new \InvalidArgumentException("VendorPart producerId must be positive.");
        if ($partsId    <= 0) throw new \InvalidArgumentException("VendorPart partsId must be positive.");
        if ($vendorJmId <= 0) throw new \InvalidArgumentException("VendorPart vendorJmId must be positive.");
        if ($fullPackQuantity <= 0) throw new \InvalidArgumentException("VendorPart fullPackQuantity must be positive.");
        $vendorPartNo = trim($vendorPartNo);
        if ($vendorPartNo === '') throw new \InvalidArgumentException("VendorPart vendorPartNo cannot be empty.");
        $producerPartNo = ($producerPartNo === null || $producerPartNo === '')
            ? null : trim($producerPartNo);

        $newId = $MsaDB->insert(
            'list__vendor_part',
            ['vendor_id', 'producer_id', 'parts_id', 'vendor_part_no', 'producer_part_no', 'vendor_jm_id', 'isActive', 'comment'],
            [$vendorId, $producerId, $partsId, $vendorPartNo, $producerPartNo, $vendorJmId, 1, $comment]
        );
        $MsaDB->db->prepare(
            "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
        )->execute([$newId, $fullPackQuantity]);
        return $newId;
    }

    /**
     * Update an existing VendorPart header row and refresh its pack-size
     * set atomically. Replaces the previous single-pack DELETE+INSERT
     * with a full pack-set replacement wrapped in a transaction, so the
     * header row and the pack table stay consistent.
     *
     * Validation mirrors `create()`: every FK must be positive,
     * vendorPartNo must be non-empty, every pack size must be > 0.
     * Duplicates are deduped. An empty pack set is now allowed (parts
     * that aren't packaged) — the `list__vendor_part_pack` table simply
     * holds zero rows for such VPs.
     *
     * Pack sizes live entirely in `list__vendor_part_pack`; the
     * `list__vendor_part` header row does NOT carry a denormalised pack
     * column (the `full_pack_quantity` column referenced by the legacy
     * schema dump and several planning docs is not present in the
     * running DB — see `docs/procurement/PLAN.md` and the column audit
     * note). The header update here therefore skips that column and the
     * pack-set refresh is the single source of truth.
     *
     * @param float[] $packQuantities ascending list of positive pack sizes
     */
    public function update(
        int $id,
        int $vendorId,
        int $producerId,
        int $partsId,
        string $vendorPartNo,
        int $vendorJmId,
        array $packQuantities,
        bool $isActive,
        ?string $comment,
        ?string $producerPartNo = null
    ): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorId   <= 0) throw new \InvalidArgumentException("VendorPart vendorId must be positive.");
        if ($producerId <= 0) throw new \InvalidArgumentException("VendorPart producerId must be positive.");
        if ($partsId    <= 0) throw new \InvalidArgumentException("VendorPart partsId must be positive.");
        if ($vendorJmId <= 0) throw new \InvalidArgumentException("VendorPart vendorJmId must be positive.");
        $vendorPartNo = trim($vendorPartNo);
        if ($vendorPartNo === '') throw new \InvalidArgumentException("VendorPart vendorPartNo cannot be empty.");

        // Normalise the pack set: drop non-positive / non-numeric
        // entries, dedupe, sort ASC. Empty pack set is allowed.
        $cleaned = [];
        foreach ($packQuantities as $v) {
            if (is_numeric($v)) {
                $f = (float)str_replace(',', '.', (string)$v);
                if ($f > 0) { $cleaned[] = $f; }
            }
        }
        $cleaned = array_values(array_unique($cleaned));
        sort($cleaned);

        $producerPartNo = ($producerPartNo === null || $producerPartNo === '')
            ? null : trim($producerPartNo);

        // Atomic header + pack refresh: either the whole write lands or
        // nothing does. PDO exception rolls back automatically when
        // inTransaction() is false on entry, so the caller doesn't need
        // an explicit try/except for the rollback path.
        $MsaDB->db->beginTransaction();
        try {
            $ok = $MsaDB->update(
                'list__vendor_part',
                [
                    'vendor_id'         => $vendorId,
                    'producer_id'       => $producerId,
                    'parts_id'          => $partsId,
                    'vendor_part_no'    => $vendorPartNo,
                    'producer_part_no'  => $producerPartNo,
                    'vendor_jm_id'      => $vendorJmId,
                    'isActive'          => $isActive ? 1 : 0,
                    'comment'           => $comment,
                ],
                'id',
                $id
            );
            // $ok is true when at least one row matched (PDO rowCount).
            // We don't fail the whole transaction on a no-op — the pack
            // table refresh below still has to run since packQuantities
            // is now the source of truth for pack sizes.

            // Refresh pack table: DELETE all existing rows, then INSERT
            // the new set. INSERT IGNORE for defence-in-depth against
            // any residual duplicates (the caller is supposed to dedupe
            // already, but the UNIQUE index (vendor_part_id,
            // full_pack_quantity) is the real guard).
            $delStmt = $MsaDB->db->prepare("DELETE FROM `list__vendor_part_pack` WHERE vendor_part_id = ?");
            $delStmt->execute([$id]);

            $insStmt = $MsaDB->db->prepare(
                "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
            );
            foreach ($cleaned as $qty) {
                $insStmt->execute([$id, $qty]);
            }

            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $inner) {
            if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
            throw $inner;
        }
    }

    /**
     * Pack sizes for the given vendor_part_id, sorted ascending. Thin
     * wrapper over the same pack query `getById` already uses
     * internally — exposed so callers that only need pack data don't
     * have to fetch the full VendorPart row.
     *
     * @return float[] empty array if the VP has no pack rows
     */
    public function getPackQuantities(int $vendorPartId): array {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare(
            "SELECT full_pack_quantity
               FROM `list__vendor_part_pack`
              WHERE vendor_part_id = ?
              ORDER BY full_pack_quantity ASC"
        );
        $stmt->execute([$vendorPartId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = (float)$row['full_pack_quantity'];
        }
        return $out;
    }

    public function toggleActive(int $id, bool $isActive): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'list__vendor_part',
            ['isActive' => $isActive ? 1 : 0],
            'id',
            $id
        );
    }

    public function existsForVendorAndPartNo(int $vendorId, string $vendorPartNo): bool {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT id FROM `list__vendor_part` WHERE vendor_id = ? AND vendor_part_no = ? LIMIT 1");
        $stmt->execute([$vendorId, $vendorPartNo]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Producers that appear in at least one `list__vendor_part` row for
     * any of the given vendor IDs. Used by the vendor-parts filter card
     * to cascade-narrow the producer dropdown when a vendor selection
     * narrows the data.
     *
     * Empty $vendorIds → returns the full producer list (no INNER JOIN).
     * Returns [['id' => int, 'name' => string], ...] sorted by name ASC.
     *
     * @param int[] $vendorIds
     */
    public function getProducersForVendorIds(array $vendorIds): array {
        $MsaDB = $this->MsaDB;
        $ids = array_values(array_filter(array_map('intval', $vendorIds), fn($v) => $v > 0));

        if ($ids === []) {
            $stmt = $MsaDB->db->query("SELECT id, name FROM `list__producer` ORDER BY name ASC");
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT DISTINCT p.id, p.name
                    FROM `list__producer` p
                    INNER JOIN `list__vendor_part` vp ON vp.producer_id = p.id
                    WHERE vp.vendor_id IN ($placeholders)
                    ORDER BY p.name ASC";
            $stmt = $MsaDB->db->prepare($sql);
            $stmt->execute($ids);
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $out;
    }

    /**
     * Symmetric counterpart to getProducersForVendorIds(): vendors that
     * appear in at least one `list__vendor_part` row for any of the
     * given producer IDs. Empty $producerIds → returns the full vendor
     * list. Returns [['id' => int, 'name' => string], ...] sorted by
     * name ASC.
     *
     * @param int[] $producerIds
     */
    public function getVendorsForProducerIds(array $producerIds): array {
        $MsaDB = $this->MsaDB;
        $ids = array_values(array_filter(array_map('intval', $producerIds), fn($v) => $v > 0));

        if ($ids === []) {
            $stmt = $MsaDB->db->query("SELECT id, name FROM `list__vendor` ORDER BY name ASC");
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT DISTINCT v.id, v.name
                    FROM `list__vendor` v
                    INNER JOIN `list__vendor_part` vp ON vp.vendor_id = v.id
                    WHERE vp.producer_id IN ($placeholders)
                    ORDER BY v.name ASC";
            $stmt = $MsaDB->db->prepare($sql);
            $stmt->execute($ids);
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $out;
    }
}
