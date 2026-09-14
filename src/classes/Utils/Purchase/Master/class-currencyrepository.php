<?php
namespace Atte\Utils\Purchase\Master;

use Atte\DB\MsaDB;

/**
 * Read-only lookup over `list__currency` (populated by the procurement
 * migration script). Powers bootstrap-select dropdowns — currently the
 * vendor edit page's "Domyślna waluta" picker.
 *
 * Currencies are soft references elsewhere in the schema
 * (`list__vendor.default_currency`, `purchase__rfq_item.currency`,
 * `purchase__order_item.currency` are all unconstrained VARCHAR(8)).
 * The dropdown enforces valid values on new writes; existing rows
 * with codes outside this list are tolerated (and `getActiveEnsuring()`
 * brings them back into view as a defensive measure).
 */
class CurrencyRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB) {
        $this->MsaDB = $MsaDB;
    }

    /**
     * Return all active currencies, ordered by ISO code (alphabetical).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getActive(): array {
        $sql = "SELECT `code`, `name`
                  FROM `list__currency`
                 WHERE `isActive` = 1
                 ORDER BY `code` ASC";
        $rows = $this->MsaDB->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'code' => (string)$r['code'],
                'name' => (string)$r['name'],
            ];
        }
        return $out;
    }

    /**
     * Same as getActive(), but guarantees the supplied code appears in
     * the returned list even if it's inactive or missing. The synthetic
     * row is prefixed with a "(nieaktywna — wycofano)" suffix on its
     * name so the operator sees they're looking at a stale value.
     *
     * Useful when rendering a vendor whose `default_currency` was set
     * manually (or backfilled from an old free-text order) and isn't
     * in the active currency set anymore.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getActiveEnsuring(string $code): array {
        $code = strtoupper(trim($code));
        $list = $this->getActive();

        if ($code === '') {
            return $list;
        }
        foreach ($list as $row) {
            if ($row['code'] === $code) {
                return $list;
            }
        }
        // Prepend the missing code so the select renders the vendor's
        // current value rather than a blank option.
        array_unshift($list, [
            'code' => $code,
            'name' => $code . ' (nieaktywna — spoza listy)',
        ]);
        return $list;
    }
}
