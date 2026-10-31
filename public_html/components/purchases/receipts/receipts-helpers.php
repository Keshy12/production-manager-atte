<?php
/**
 * Drobne helpery widoków przyjęć:
 *   /purchase/receipts       (receipts-view.php — lista)
 *   /purchase/receipts/view  (receipt-view.php  — szczegóły)
 *
 * Wyłącznie formatowanie do wyświetlania — żadnych zapytań do bazy
 * i żadnej logiki stanów magazynowych (stany liczą triggery MySQL).
 * Nazwy z prefiksem rx_ i owinięte w function_exists(), żeby podwójne
 * dołączenie pliku nie kończyło się „Cannot redeclare".
 */

if (!function_exists('rx_e')) {

    /** Escape do HTML (treść i atrybuty). */
    function rx_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Ilość do wyświetlenia: przecinek dziesiętny, maks. 4 miejsca, bez
     * zer na końcu. Separator tysięcy (twarda spacja) dopiero od 10 000 —
     * tak samo grupuje toLocaleString('pl-PL') po stronie JS, więc liczby
     * z PHP i z JS wyglądają identycznie.
     */
    function rx_fmt_qty($value): string
    {
        $v = (float)$value;
        $group = abs($v) >= 10000 ? "\u{00A0}" : '';
        $s = number_format($v, 4, ',', $group);
        $s = rtrim(rtrim($s, '0'), ',');
        return ($s === '-0' || $s === '') ? '0' : $s;
    }

    /** 'Y-m-d H:i:s' → 'd.m.Y' (albo '—'). */
    function rx_fmt_date(?string $dt): string
    {
        if ($dt === null || $dt === '') return '—';
        $ts = strtotime($dt);
        return $ts ? date('d.m.Y', $ts) : $dt;
    }

    /**
     * 'Y-m-d H:i:s' → 'H:i'. Przyjęcia wpisane wstecz mają samą datę
     * (godzina 00:00:00) — wtedy zwracamy pusty string zamiast
     * udawanej północy.
     */
    function rx_fmt_time(?string $dt): string
    {
        if ($dt === null || strlen($dt) < 16) return '';
        $hm = substr($dt, 11, 5);
        return $hm === '00:00' ? '' : $hm;
    }

    /** „dzisiaj" / „wczoraj" albo null. */
    function rx_relative_day(?string $dt): ?string
    {
        if ($dt === null || $dt === '') return null;
        $day = substr($dt, 0, 10);
        if ($day === date('Y-m-d')) return 'dzisiaj';
        if ($day === date('Y-m-d', strtotime('-1 day'))) return 'wczoraj';
        return null;
    }

    /** Inicjały do kółka przy nazwisku („Jan Kowalski" → „JK"). */
    function rx_initials(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '' || $name[0] === '#') return '?';
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= function_exists('mb_substr') ? mb_substr($p, 0, 1, 'UTF-8') : substr($p, 0, 1);
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($out, 'UTF-8') : strtoupper($out);
    }

    /**
     * Etykieta + odcień plakietki stanu zamówienia. Ta sama mapa co
     * STATE_META w receipts-view.js — stan PO ma wyglądać identycznie
     * na liście i na stronie szczegółów.
     */
    function rx_state_meta(?string $state): array
    {
        $map = [
            'draft'              => ['Szkic',               'neutral'],
            'sent'               => ['Wysłane',             'violet'],
            'responded'          => ['Odpowiedź',           'cyan'],
            'confirmed'          => ['Potwierdzone',        'blue'],
            'in_progress'        => ['Częściowo przyjęte',  'cyan'],
            'partially_received' => ['Częściowo odebrane',  'amber'],
            'received'           => ['Odebrane',            'green'],
            'cancelled'          => ['Anulowane',           'red'],
        ];
        $m = $map[(string)$state] ?? [(string)$state, 'neutral'];
        return ['label' => $m[0], 'tone' => $m[1]];
    }

    /** Plakietka stanu zamówienia (HTML). */
    function rx_state_badge(?string $state): string
    {
        $m = rx_state_meta($state);
        return '<span class="rx-badge rx-badge--' . rx_e($m['tone']) . '">' . rx_e($m['label']) . '</span>';
    }

    /** Odmiana: 1 pozycja / 2-4 pozycje / 5+ pozycji. */
    function rx_plural(int $n, string $one, string $few, string $many): string
    {
        $a = abs($n);
        if ($a === 1) return $one;
        $l = $a % 10;
        $l2 = $a % 100;
        if ($l >= 2 && $l <= 4 && ($l2 < 12 || $l2 > 14)) return $few;
        return $many;
    }
}
