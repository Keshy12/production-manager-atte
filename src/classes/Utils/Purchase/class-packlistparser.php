<?php
namespace Atte\Utils\Purchase;

/**
 * Shared helper for parsing the `/`-separated pack-quantity strings used by
 * the vendor importer and any future caller that needs to normalise the
 * shape (e.g. vp-add.php applies its own validation on top of POST data).
 *
 * Lives at `src/classes/Utils/Purchase/class-packlistparser.php` so any
 * purchase-domain code can `use Atte\Utils\Purchase\PackListParser;`.
 * After adding the file: `composer dump-autoload` (autoloader is classmap).
 */
class PackListParser {
    /**
     * Parse "100/1000/5000" → [100.0, 1000.0, 5000.0], deduped + sorted ASC.
     * Tokens may use ',' as decimal separator (Polish locale); whitespace ignored.
     * Empty / invalid tokens dropped; non-positive values dropped.
     * Returns [1.0] for empty input.
     */
    public static function parse(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [1.0];
        }
        $out = [];
        foreach (preg_split('~/~', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            // Polish locale: comma is the decimal separator; spaces are
            // tolerated inside the token (e.g. "1 000,5"). Anything
            // non-numeric coerces to 0 via PHP's float cast.
            $n = (float)str_replace(',', '.', str_replace(' ', '', $tok));
            if ($n > 0) {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}