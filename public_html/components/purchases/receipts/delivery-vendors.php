<?php
/**
 * AJAX (POST): active vendors for the receiving screen, with counters.
 *
 * Response:
 *   {success:true, vendors:[{id, name, default_currency_code,
 *     open_po_count, open_line_count, draft_count}]}
 * Sorted open_po_count DESC, then name.
 */
require_once __DIR__ . '/delivery-common.php';

use Atte\Utils\Purchase\Order\DeliveryMatcher;

delivery_guard('POST');

delivery_run(function ($MsaDB) {
    $states = "'" . implode("','", DeliveryMatcher::OPEN_PO_STATES) . "'";
    $stmt = $MsaDB->db->prepare(
        "SELECT v.id, v.name, c.code AS default_currency_code,
                COALESCE(o.open_po_count, 0)   AS open_po_count,
                COALESCE(o.open_line_count, 0) AS open_line_count,
                COALESCE(d.draft_count, 0)     AS draft_count
           FROM `list__vendor` v
           LEFT JOIN `list__currency` c ON c.id = v.default_currency
           LEFT JOIN (
                SELECT po.vendor_id,
                       COUNT(DISTINCT po.id) AS open_po_count,
                       COUNT(i.id)           AS open_line_count
                  FROM `purchase__order` po
                  JOIN `purchase__order_item` i ON i.po_id = po.id
                 WHERE po.state IN ({$states})
                   AND i.quantity - i.quantity_received > 0.000001
                 GROUP BY po.vendor_id
           ) o ON o.vendor_id = v.id
           LEFT JOIN (
                SELECT vendor_id, COUNT(*) AS draft_count
                  FROM `purchase__delivery`
                 WHERE state = 'draft'
                 GROUP BY vendor_id
           ) d ON d.vendor_id = v.id
          WHERE v.isActive = 1
          ORDER BY open_po_count DESC, v.name ASC"
    );
    $stmt->execute();

    $vendors = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $vendors[] = [
            'id'                    => (int)$r['id'],
            'name'                  => $r['name'],
            'default_currency_code' => $r['default_currency_code'] !== null ? strtoupper($r['default_currency_code']) : 'PLN',
            'open_po_count'         => (int)$r['open_po_count'],
            'open_line_count'       => (int)$r['open_line_count'],
            'draft_count'           => (int)$r['draft_count'],
        ];
    }
    return ['success' => true, 'vendors' => $vendors];
});
