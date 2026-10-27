/**
 * vendors-view.js
 *
 * Admin view for /admin/purchase/vendors. The page is filter-driven and
 * laid out like /purchase/documents: a row of stat tiles (filter
 * shortcuts), a compact toolbar, a results line with the top pager, and
 * an AJAX-rendered table in a panel with the full pager below. Adding a
 * new vendor is done via a dedicated edit page at
 * /admin/purchase/vendors/edit (the "Dodaj dostawcę" button in the page
 * header navigates there).
 *
 * The edit flow is a dedicated page — see /admin/purchase/vendors/edit
 * (wired in index.php and rendered by edit/vendor-edit-view.php). Each
 * row's "Edytuj" button is a plain anchor that navigates there. The
 * legacy modal + vendor-get.php / vendor-add.php / vendor-update.php
 * / vendor-toggle-active.php / vendor-detail.php endpoints have all
 * been removed.
 *
 * Suppliers + vendor-parts are managed inline on the edit page (full
 * supplier CRUD + read-only vendor-parts list); no detail modal.
 *
 * Filter state:
 *   - three "dimensions" live in `filterState` (status, hasArticles,
 *     hasSuppliers) and are shown by BOTH the stat tiles and the toolbar
 *     segmented buttons — syncFilterUi() paints both from that one object,
 *     so they can never disagree;
 *   - search and the date range live in their inputs.
 *   Defaults (isDefault): status = active, everything else off.
 *
 * Tile groups (vendors-view.php), both made of switch-style toggles:
 *   - "Status": Aktywni / Nieaktywni (data-status-flag). Two independent
 *     switches that map onto the endpoint's single `status` value:
 *     only Aktywni -> 'active' (default), only Nieaktywni -> 'inactive',
 *     both -> 'all'. At least one stays on: clicking the only lit one
 *     does nothing (it is marked aria-disabled + titled). There is no
 *     "Wszystkie" tile or button — "all" is simply both switches on.
 *     The toolbar's Status segment (Aktywne / Nieaktywne) uses the same
 *     data-status-flag buttons, painted from the same filterState.
 *   - "Braki powiązań": Bez artykułów / Bez osób kontaktowych
 *     (data-dim / data-value). Independent on/off; clicking a lit tile
 *     resets only that dimension to its default.
 *
 * Tile counts come from `stats` in the vendor-list.php response: each is
 * the count under the CURRENT filters with only that tile's dimension
 * overridden (stats.active / stats.inactive for the status switches;
 * stats.all is not used by the UI any more).
 *
 * Pattern mirrors vendor-parts-view.js / producers-view.js — same
 * shape, adapted for the wider vendor schema (id, name, address,
 * additionalData, leadTimeDays, comment, isActive, supplierCount,
 * vendorPartCount, createdAt).
 */
$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Vendors/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/vendors/edit?id=";
    const addUrl   = window.location.origin + "/atte_ms_new/admin/purchase/vendors/edit";

    const DEFAULTS = { status: 'active', hasArticles: 'all', hasSuppliers: 'all' };
    const STATUS_LOCK_TITLE = 'Co najmniej jeden status musi być zaznaczony';
    const SEARCH_DEBOUNCE_MS = 300;

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // "z 1 dostawcy" / "z 5 dostawców" (dopełniacz).
    function vendorsGen(n) {
        return n === 1 ? 'dostawcy' : 'dostawców';
    }

    // ============================================================
    // State
    // ============================================================
    // itemsPerPage comes from the server response (vendor-list.php) so
    // the client and backend share one source of truth.
    let itemsPerPage = 10;
    let currentPage = 1;
    let totalCount  = null;      // null = unknown yet (loading)
    let listXhr = null;
    let listSeq = 0;
    let hasLoadedOnce = false;
    let searchTimer = null;
    const filterState = $.extend({}, DEFAULTS);

    // Surface the ?updated=N / ?created=1 success flashes sent back
    // from the edit page. Show them once, then strip from URL via
    // history.replaceState so a refresh doesn't re-show the toast.
    const params = new URLSearchParams(window.location.search);
    if (params.has('updated')) {
        const updatedId = params.get('updated');
        setAlert('Zapisano zmiany (dostawca #' + esc(updatedId) + ').', 'success');
        params.delete('updated');
    }
    if (params.has('created')) {
        const createdId = params.get('created');
        setAlert('Dostawca #' + esc(createdId) + ' został dodany.', 'success');
        params.delete('created');
    }
    if (params.toString() !== window.location.search.replace(/^\?/, '')) {
        const newQuery = params.toString();
        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
        window.history.replaceState({}, '', newUrl);
    }

    // ============================================================
    // Filters
    // ============================================================

    // Current filters as the UI sees them ('all' | 'used' | 'unused' for
    // the two tri-state dimensions).
    function gatherFilters() {
        return {
            status:       filterState.status,
            hasArticles:  filterState.hasArticles,
            hasSuppliers: filterState.hasSuppliers,
            search:       ($('#vrFilterSearch').val() || '').trim(),
            dateFrom:     $('#vrFilterDateFrom').val() || '',
            dateTo:       $('#vrFilterDateTo').val()   || '',
        };
    }

    // True when nothing differs from the page defaults.
    function isDefault(f) {
        return f.status === DEFAULTS.status
            && f.hasArticles === DEFAULTS.hasArticles
            && f.hasSuppliers === DEFAULTS.hasSuppliers
            && f.search === ''
            && f.dateFrom === ''
            && f.dateTo === '';
    }

    // Wire format for the tri-state dimensions: 'used' -> 'true',
    // 'unused' -> 'false', 'all' -> omitted (the repo treats it as no-op).
    function triBool(raw) {
        if (raw === 'used')   return 'true';
        if (raw === 'unused') return 'false';
        return null;
    }

    function payload(f) {
        const data = {
            status:   f.status,
            search:   f.search,
            dateFrom: f.dateFrom,
            dateTo:   f.dateTo,
            page:     currentPage,
        };
        const a = triBool(f.hasArticles);
        const s = triBool(f.hasSuppliers);
        if (a !== null) { data.hasArticles = a; }
        if (s !== null) { data.hasSuppliers = s; }
        return data;
    }

    // Status as two switches. filterState.status stays 'active' |
    // 'inactive' | 'all' (the endpoint's wire format); the switches are
    // derived from it and mapped back.
    function statusFlags(status) {
        return {
            active:   status === 'active'   || status === 'all',
            inactive: status === 'inactive' || status === 'all',
        };
    }

    function statusFromFlags(flags) {
        return (flags.active && flags.inactive) ? 'all' : (flags.active ? 'active' : 'inactive');
    }

    // Flips one status switch. The last lit switch cannot be turned off
    // (both off would always be an empty list) — nothing happens, no request.
    function toggleStatus(which) {
        const flags = statusFlags(filterState.status);
        flags[which] = !flags[which];
        if (!flags.active && !flags.inactive) { return; }
        setDimension('status', statusFromFlags(flags));
    }

    // Paint every control that mirrors the filters: toolbar segments,
    // stat tiles, status switches, date range highlight and the
    // "Wyczyść filtry" link.
    function syncFilterUi(f) {
        $('.vx-toolbar .rx-seg [data-dim]').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
        $('#vrStats .rx-stat[data-dim]').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });

        // Status switches (tiles + toolbar buttons). The only lit one is
        // "locked": still focusable, but aria-disabled and explained in
        // its tooltip so the dead click is understandable.
        const flags = statusFlags(f.status);
        $('#vendorsView [data-status-flag]').each(function() {
            const $el = $(this);
            const which = $el.attr('data-status-flag');
            const other = which === 'active' ? 'inactive' : 'active';
            const on = flags[which];
            const locked = on && !flags[other];
            if ($el.attr('data-orig-title') === undefined) {
                $el.attr('data-orig-title', $el.attr('title') || '');
            }
            const title = locked ? STATUS_LOCK_TITLE : $el.attr('data-orig-title');
            if (title) { $el.attr('title', title); } else { $el.removeAttr('title'); }
            $el.toggleClass('is-active', on)
               .toggleClass('is-locked', locked)
               .attr('aria-pressed', on ? 'true' : 'false')
               .attr('aria-disabled', locked ? 'true' : 'false');
        });

        $('#vrDateRange').toggleClass('is-active', f.dateFrom !== '' || f.dateTo !== '');
        $('#vrClear').toggleClass('d-none', isDefault(f));
    }

    // A filter (not page) changed: back to page 1, count unknown again.
    function filtersChanged() {
        clearTimeout(searchTimer);
        currentPage = 1;
        totalCount = null;
        loadList();
    }

    // Sets one dimension; no request when it is already that value.
    function setDimension(dim, value) {
        if (filterState[dim] === value) { return; }
        filterState[dim] = value;
        filtersChanged();
    }

    // Back to the page defaults (same state as on first load).
    function resetFilters() {
        $.extend(filterState, DEFAULTS);
        $('#vrFilterSearch').val('');
        $('#vrFilterDateFrom').val('');
        $('#vrFilterDateTo').val('');
        filtersChanged();
    }

    // ============================================================
    // Rendering
    // ============================================================

    function dash() {
        return '<span class="text-muted">—</span>';
    }

    // Powiązania: osoby kontaktowe + artykuły in one cell. Zero stays
    // visible (dimmed) so a vendor with no links is easy to spot.
    function linkCount(icon, count, label) {
        const n = parseInt(count, 10);
        const val = (isNaN(n) || n < 0) ? 0 : n;
        return '<span title="' + label + ': ' + val + '">' +
            '<i class="bi ' + icon + '"></i>' +
            (val === 0 ? '<span class="vr-zero">0</span>' : String(val)) +
        '</span>';
    }

    function renderRow(v) {
        const isActive = v.isActive === true || v.isActive === 1 || v.isActive === '1';
        const rowClass = isActive ? '' : 'vr-row--inactive';

        // Name + muted lines under it: address (second line) and, when
        // present, the comment on its own line — icon (full text in
        // title) followed by the comment text, truncated with an ellipsis
        // by CSS. The comment is always visible so operators can read
        // e.g. the auto-deactivation reason without hovering.
        const rawAddr = (v.address == null ? '' : String(v.address)).trim();
        const rawCmt  = (v.comment == null ? '' : String(v.comment)).trim();
        const addrHtml = rawAddr
            ? '<span class="vr-sub" title="' + esc(rawAddr) + '">' + esc(rawAddr) + '</span>'
            : '';
        const cmtHtml = rawCmt
            ? '<span class="vr-cmt">' +
                '<i class="bi bi-journal-text vr-note" title="' + esc(rawCmt) + '"></i>' +
                '<span class="vr-cmt-text" title="' + esc(rawCmt) + '">' + esc(rawCmt) + '</span>' +
              '</span>'
            : '';
        const subHtml = addrHtml + cmtHtml;

        const lt = parseInt(v.leadTimeDays, 10);
        const leadTimeCell = isNaN(lt) ? dash() : lt + ' dni';

        const linksCell =
            linkCount('bi-person', v.supplierCount, 'Osoby kontaktowe') +
            linkCount('bi-box-seam', v.vendorPartCount, 'Artykuły u dostawcy');

        const statusBadge = isActive
            ? '<span class="rx-badge rx-badge--green">Aktywny</span>'
            : '<span class="rx-badge">Nieaktywny</span>';

        const label = esc(v.name || '');
        const id = esc(v.id);

        return '<tr class="' + rowClass + '" data-id="' + id + '">' +
            '<td class="vr-col-id">' + id + '</td>' +
            '<td><span class="vr-name">' + esc(v.name || '—') + '</span>' + subHtml + '</td>' +
            '<td class="text-center vr-col-num">' + leadTimeCell + '</td>' +
            '<td class="text-center vr-col-num vr-links">' + linksCell + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm rx-btn-quiet" href="' + editBase + id + '"' +
                   ' title="Edytuj dostawcę „' + label + '”" aria-label="Edytuj dostawcę">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    // First load: shimmering placeholder rows instead of an empty panel.
    function skeletonHtml() {
        let html = '';
        for (let i = 0; i < 6; i++) {
            html += '<tr class="vx-skel-row">' +
                '<td><span class="rx-skel rx-skel--sm" style="width:1.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel" style="width:55%"></span><span class="rx-skel rx-skel--sm" style="width:35%"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:2.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:4rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--pill" style="width:4.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:2rem;margin:0 auto"></span></td>' +
            '</tr>';
        }
        $('#vrTableBody').html(html);
    }

    function emptyHtml(icon, tone, title, text, actionsHtml) {
        return '<div class="rx-empty">' +
            '<div class="rx-empty-icon' + (tone ? ' rx-empty-icon--' + tone : '') + '"><i class="bi ' + icon + '"></i></div>' +
            '<div class="rx-empty-title">' + esc(title) + '</div>' +
            '<div class="rx-empty-text">' + esc(text) + '</div>' +
            (actionsHtml ? '<div class="vx-empty-actions">' + actionsHtml + '</div>' : '') +
        '</div>';
    }

    // The status block (empty / error) replaces the table inside the panel.
    function showStatus(html) {
        $('#vrStatus').html(html).prop('hidden', false);
        $('#vrTableWrap').prop('hidden', true);
    }

    function hideStatus() {
        $('#vrStatus').empty().prop('hidden', true);
        $('#vrTableWrap').prop('hidden', false);
    }

    // Empty state: with filters on it offers a reset (the catalogue may
    // not be empty); with defaults it offers adding a vendor.
    function showEmpty(f) {
        if (!isDefault(f)) {
            showStatus(emptyHtml('bi-truck', '', 'Brak wyników',
                'Żaden dostawca nie spełnia wybranych kryteriów. Zmień lub wyczyść filtry.',
                '<button type="button" class="btn btn-sm btn-primary js-clear-filters">' +
                    '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'));
        } else {
            showStatus(emptyHtml('bi-truck', '', 'Brak aktywnych dostawców',
                'Dodaj pierwszego dostawcę albo zmień status w filtrach.',
                '<a href="' + addUrl + '" class="btn btn-sm btn-primary">' +
                    '<i class="bi bi-plus-circle"></i> Dodaj dostawcę</a>'));
        }
    }

    function showError(message) {
        showStatus(emptyHtml('bi-exclamation-octagon', 'danger',
            'Nie udało się wczytać dostawców', message,
            '<button type="button" class="btn btn-sm btn-primary js-list-retry">' +
                '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
    }

    // Tile counters from the response; '–' when unknown (error).
    function renderStats(stats) {
        $('#vrStats .rx-stat').each(function() {
            const n = stats ? parseInt(stats[$(this).attr('data-stat')], 10) : NaN;
            const known = !isNaN(n);
            $(this).find('[data-stat-value]').text(known ? n : '–');
            $(this).toggleClass('is-zero', known && n === 0);
        });
        $('#vrStats').removeClass('is-loading');
    }

    // Results line: range + what the status filter currently shows.
    function renderSummary(f) {
        const $el = $('#vrSummary');
        if (totalCount === null) {
            $el.text('Wczytywanie dostawców…');
            return;
        }
        if (totalCount === 0) {
            $el.html((isDefault(f) ? '' : '<i class="bi bi-funnel-fill"></i> ') + 'Brak wyników');
            return;
        }
        const start = (currentPage - 1) * itemsPerPage + 1;
        const end   = Math.min(currentPage * itemsPerPage, totalCount);
        const scope = f.status === 'active'   ? 'tylko aktywni'
                    : f.status === 'inactive' ? 'tylko nieaktywni'
                    : 'aktywni i nieaktywni';
        $el.html((isDefault(f) ? '' : '<i class="bi bi-funnel-fill"></i> ') +
            'Wyświetlanie <strong>' + start + '–' + end + '</strong> z <strong>' + totalCount +
            '</strong> ' + vendorsGen(totalCount) +
            ' <span class="vx-sep text-faint">·</span> ' + scope);
    }

    // Pager: compact one in the results line, full one under the panel.
    function renderPagination() {
        const $top = $('#vrPaginationTop');
        const $bottom = $('#vrPaginationBottom');
        if (totalCount === null || totalCount === 0) {
            $top.empty();
            $bottom.empty();
            return;
        }
        const pages = Math.max(1, Math.ceil(totalCount / itemsPerPage));
        const first = (currentPage - 1) * itemsPerPage + 1;
        const last  = Math.min(currentPage * itemsPerPage, totalCount);
        const atStart = currentPage <= 1;
        const atEnd   = currentPage >= pages;

        function btn(action, icon, title, disabled) {
            return '<button type="button" class="btn rx-btn-quiet vx-page-btn" data-action="' + action + '"' +
                ' title="' + title + '" aria-label="' + title + '"' + (disabled ? ' disabled' : '') + '>' +
                '<i class="bi ' + icon + '"></i></button>';
        }

        let items = '';
        for (let p = 1; p <= pages; p++) {
            items += '<a class="dropdown-item vx-page-item' + (p === currentPage ? ' active' : '') +
                '" href="#" data-page="' + p + '">Strona ' + p + '</a>';
        }

        function pageMenu(dir) {
            return '<div class="btn-group btn-group-sm ' + dir + '">' +
                '<button type="button" class="btn rx-btn-quiet dropdown-toggle rx-pager-current" data-toggle="dropdown">' +
                'Strona ' + currentPage + ' z ' + pages + '</button>' +
                '<div class="dropdown-menu dropdown-menu-right rx-pager-menu">' + items + '</div>' +
                '</div>';
        }

        if (pages > 1) {
            $top.html('<div class="btn-group btn-group-sm vx-pager-compact" role="group" aria-label="Strony (góra)">' +
                btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart) +
                pageMenu('') +
                btn('next', 'bi-chevron-right', 'Następna strona', atEnd) +
                '</div>');
        } else {
            $top.empty();
        }

        let html = '<div class="rx-pager">' +
            '<div class="rx-pager-info">Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>' +
            totalCount + '</strong> ' + vendorsGen(totalCount) + '</div>';
        if (pages > 1) {
            html += '<div class="btn-group btn-group-sm rx-pager-nav" role="group" aria-label="Strony (dół)">' +
                btn('first', 'bi-chevron-double-left', 'Pierwsza strona', atStart) +
                btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart) +
                pageMenu('dropup') +
                btn('next', 'bi-chevron-right', 'Następna strona', atEnd) +
                btn('last', 'bi-chevron-double-right', 'Ostatnia strona', atEnd) +
                '</div>';
        }
        html += '</div>';
        $bottom.html(html);
    }

    function goToPage(page, fromBottom) {
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / itemsPerPage)) : 1;
        const next = Math.min(Math.max(1, page), pages);
        if (next === currentPage) { return; }
        currentPage = next;
        loadList();
        if (fromBottom) {
            const top = $('#vendorsView .vx-toolbar').offset().top - 16;
            if ($(window).scrollTop() > top) { $('html, body').animate({ scrollTop: top }, 200); }
        }
    }

    function setBusy(on) {
        $('#vrRefresh').prop('disabled', on).toggleClass('is-spinning', on);
        $('#vrPanel').toggleClass('is-loading', on && hasLoadedOnce);
    }

    // ============================================================
    // Loading
    // ============================================================
    // Every call supersedes the previous one (abort + sequence number),
    // so fast clicks on tiles / typing never show an older answer.
    function loadList() {
        const f = gatherFilters();
        syncFilterUi(f);

        const seq = ++listSeq;
        if (listXhr) { listXhr.abort(); }

        setBusy(true);
        // Not before the first answer: the ?created= / ?updated= flash set
        // above must survive the initial load.
        if (hasLoadedOnce) { setAlert('', null); }
        if (!hasLoadedOnce) { skeletonHtml(); }
        $('#vrStats').addClass('is-loading');
        renderSummary(f);

        listXhr = postAjax('vendor-list.php', payload(f))
            .done(function(r) {
                if (seq !== listSeq) { return; }
                if (!r || !r.success) {
                    handleError((r && r.error) || 'Błąd ładowania listy');
                    return;
                }
                totalCount = parseInt(r.total, 10) || 0;
                itemsPerPage = parseInt(r.itemsPerPage, 10) || itemsPerPage;

                // Page past the end (e.g. vendors vanished after a refresh).
                if ((r.rows || []).length === 0 && totalCount > 0 && currentPage > 1) {
                    currentPage = 1;
                    loadList();
                    return;
                }

                hasLoadedOnce = true;
                renderStats(r.stats);
                if (totalCount === 0) {
                    $('#vrTableBody').empty();
                    showEmpty(f);
                } else {
                    hideStatus();
                    $('#vrTableBody').html((r.rows || []).map(renderRow).join(''));
                }
                renderPagination();
                renderSummary(f);
            })
            .fail(function(xhr, status) {
                if (status === 'abort' || seq !== listSeq) { return; }
                handleError((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'Błąd komunikacji z serwerem');
            })
            .always(function() {
                if (seq === listSeq) { setBusy(false); }
            });
    }

    function handleError(message) {
        hasLoadedOnce = true;   // skeleton is gone either way
        totalCount = null;
        setAlert(esc(message), 'danger');
        $('#vrTableBody').empty();
        showError(message);
        renderStats(null);
        $('#vrSummary').text('Lista niedostępna.');
        $('#vrPaginationTop, #vrPaginationBottom').empty();
    }

    // ============================================================
    // Events
    // ============================================================

    // Status switches (tiles and toolbar buttons alike): flip one, keep
    // at least one lit.
    $('#vendorsView').on('click', '[data-status-flag]', function() {
        toggleStatus($(this).attr('data-status-flag'));
    });

    // "Braki powiązań" tiles: set one dimension; a second click on the lit
    // tile puts just that dimension back to its default.
    $('#vrStats').on('click', '.rx-stat[data-dim]', function() {
        const dim = $(this).attr('data-dim');
        const value = $(this).attr('data-value');
        setDimension(dim, filterState[dim] === value ? DEFAULTS[dim] : value);
    });

    // Toolbar segments: plain set.
    $('.vx-toolbar').on('click', '.rx-seg [data-dim]', function() {
        setDimension($(this).attr('data-dim'), $(this).attr('data-value'));
    });

    // Search: short delay so we don't query on every keystroke; Enter
    // sends immediately.
    $('#vrFilterSearch').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(filtersChanged, SEARCH_DEBOUNCE_MS);
    }).on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            filtersChanged();
        }
    });

    $('#vrFilterDateFrom, #vrFilterDateTo').on('change', filtersChanged);

    // Refresh keeps the current page.
    $('#vrRefresh').on('click', function() { loadList(); });

    // One reset for the link in the results line and the empty state.
    $('#vrClear').on('click', resetFilters);
    $('#vrStatus').on('click', '.js-clear-filters', resetFilters);
    $('#vrStatus').on('click', '.js-list-retry', function() { loadList(); });

    // Pagers (top and bottom share the same handlers).
    $('#vrPaginationTop, #vrPaginationBottom').on('click', '.vx-page-btn', function() {
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / itemsPerPage)) : 1;
        const fromBottom = $(this).closest('#vrPaginationBottom').length > 0;
        const act = $(this).attr('data-action');
        if (act === 'first')     { goToPage(1, fromBottom); }
        else if (act === 'prev') { goToPage(currentPage - 1, fromBottom); }
        else if (act === 'next') { goToPage(currentPage + 1, fromBottom); }
        else if (act === 'last') { goToPage(pages, fromBottom); }
    });
    $('#vrPaginationTop, #vrPaginationBottom').on('click', '.vx-page-item', function(e) {
        e.preventDefault();
        goToPage(parseInt($(this).attr('data-page'), 10) || 1, $(this).closest('#vrPaginationBottom').length > 0);
    });

    // First paint.
    loadList();
});
