/**
 * producers-view.js
 *
 * Admin view for /admin/purchase/producers. The page is filter-driven and
 * laid out like /admin/purchase/vendors: a row of stat tiles (filter
 * shortcuts), a compact toolbar, a results line with the top pager, and
 * an AJAX-rendered table in a panel with the full pager below. Adding a
 * new producer is done via a dedicated edit page at
 * /admin/purchase/producers/edit (the "Dodaj producenta" button in the
 * page header navigates there).
 *
 * The edit flow is a dedicated page — see /admin/purchase/producers/edit
 * (wired in index.php and rendered by edit/producer-edit-view.php). Each
 * row's "Edytuj" button is a plain anchor that navigates there. The
 * legacy modal + producer-get.php / producer-add.php /
 * producer-update.php / producer-toggle-active.php endpoints have all
 * been removed.
 *
 * Filter state:
 *   - two "dimensions" live in `filterState` (status, hasArticles) and are
 *     shown by BOTH the stat tiles and the toolbar segmented buttons —
 *     syncFilterUi() paints both from that one object, so they can never
 *     disagree;
 *   - search lives in its input.
 *   Defaults (isDefault): status = active, hasArticles = all, no search.
 *
 * Tile groups (producers-view.php), both made of switch-style toggles:
 *   - "Status": Aktywni / Nieaktywni (data-status-flag). Two independent
 *     switches that map onto the endpoint's single `status` value:
 *     only Aktywni -> 'active' (default), only Nieaktywni -> 'inactive',
 *     both -> 'all'. At least one stays on: clicking the only lit one
 *     does nothing (it is marked aria-disabled + titled). There is no
 *     "Wszyscy" tile or button — "all" is simply both switches on.
 *     The toolbar's Status segment (Aktywne / Nieaktywne) uses the same
 *     data-status-flag buttons, painted from the same filterState.
 *   - Second group (label set in producers-view.php): the single "Bez artykułów" switch
 *     (data-dim / data-value). Clicking it while lit resets only that
 *     dimension to its default.
 *
 * Tile counts come from `stats` in the producer-list.php response: each is
 * the count under the CURRENT filters with only that tile's dimension
 * overridden (stats.active / stats.inactive for the status switches;
 * stats.all is not used by the UI any more).
 *
 * Pattern mirrors vendors-view.js, adapted for the narrower producer
 * schema (id, name, comment, isActive, vendorPartCount).
 */
$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Producers/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/producers/edit?id=";
    // Set by an inline script in producers-view.php; fallback keeps the
    // empty state working if that script is ever removed.
    const addUrl   = window.PR_ADD_URL || (window.location.origin + "/atte_ms_new/admin/purchase/producers/edit");

    const DEFAULTS = { status: 'active', hasArticles: 'all' };
    const STATUS_LOCK_TITLE = 'Co najmniej jeden status musi być zaznaczony';
    const SEARCH_DEBOUNCE_MS = 300;

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // "z 1 producenta" / "z 5 producentów" (dopełniacz).
    function producersGen(n) {
        return n === 1 ? 'producenta' : 'producentów';
    }

    // ============================================================
    // State
    // ============================================================
    // itemsPerPage comes from the server response (producer-list.php) so
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
        setAlert('Zapisano zmiany (producent #' + esc(updatedId) + ').', 'success');
        params.delete('updated');
    }
    if (params.has('created')) {
        const createdId = params.get('created');
        setAlert('Producent #' + esc(createdId) + ' został dodany.', 'success');
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
    // the tri-state hasArticles dimension).
    function gatherFilters() {
        return {
            status:      filterState.status,
            hasArticles: filterState.hasArticles,
            search:      ($('#prFilterSearch').val() || '').trim(),
        };
    }

    // True when nothing differs from the page defaults.
    function isDefault(f) {
        return f.status === DEFAULTS.status
            && f.hasArticles === DEFAULTS.hasArticles
            && f.search === '';
    }

    // Wire format for the tri-state dimension: 'used' -> 'true',
    // 'unused' -> 'false', 'all' -> omitted (the repo treats it as no-op).
    function triBool(raw) {
        if (raw === 'used')   return 'true';
        if (raw === 'unused') return 'false';
        return null;
    }

    function payload(f) {
        const data = {
            status: f.status,
            search: f.search,
            page:   currentPage,
        };
        const a = triBool(f.hasArticles);
        if (a !== null) { data.hasArticles = a; }
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
    // stat tiles, status switches and the "Wyczyść filtry" link.
    function syncFilterUi(f) {
        $('.prx-toolbar .rx-seg [data-dim]').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
        $('#prStats .rx-stat[data-dim]').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });

        // Status switches (tiles + toolbar buttons). The only lit one is
        // "locked": still focusable, but aria-disabled and explained in
        // its tooltip so the dead click is understandable.
        const flags = statusFlags(f.status);
        $('#producersView [data-status-flag]').each(function() {
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

        $('#prClear').toggleClass('d-none', isDefault(f));
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
        $('#prFilterSearch').val('');
        filtersChanged();
    }

    // ============================================================
    // Rendering
    // ============================================================

    // Artykuły u dostawców: icon + count. Zero stays visible (dimmed) so
    // an unused producer is easy to spot.
    function articleCount(count) {
        const n = parseInt(count, 10);
        const val = (isNaN(n) || n < 0) ? 0 : n;
        return '<span class="pr-count" title="Artykuły u dostawców: ' + val + '">' +
            '<i class="bi bi-box-seam"></i>' +
            (val === 0 ? '<span class="pr-zero">0</span>' : String(val)) +
        '</span>';
    }

    function renderRow(p) {
        const isActive = p.isActive === true || p.isActive === 1 || p.isActive === '1';
        const rowClass = isActive ? '' : 'pr-row--inactive';

        // Comment on its own line under the name: icon (full text in
        // title) followed by the comment text, truncated with an ellipsis
        // by CSS. Always visible so operators can read it without hovering.
        const rawCmt = (p.comment == null ? '' : String(p.comment)).trim();
        const cmtHtml = rawCmt
            ? '<span class="pr-cmt">' +
                '<i class="bi bi-journal-text pr-note" title="' + esc(rawCmt) + '"></i>' +
                '<span class="pr-cmt-text" title="' + esc(rawCmt) + '">' + esc(rawCmt) + '</span>' +
              '</span>'
            : '';

        const statusBadge = isActive
            ? '<span class="rx-badge rx-badge--green">Aktywny</span>'
            : '<span class="rx-badge">Nieaktywny</span>';

        const label = esc(p.name || '');
        const id = esc(p.id);

        return '<tr class="' + rowClass + '" data-id="' + id + '">' +
            '<td class="pr-col-id">' + id + '</td>' +
            '<td><span class="pr-name">' + esc(p.name || '—') + '</span>' + cmtHtml + '</td>' +
            '<td class="text-center pr-col-count">' + articleCount(p.vendorPartCount) + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm rx-btn-quiet" href="' + editBase + id + '"' +
                   ' title="Edytuj producenta „' + label + '”" aria-label="Edytuj producenta">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    // First load: shimmering placeholder rows instead of an empty panel.
    function skeletonHtml() {
        let html = '';
        for (let i = 0; i < 6; i++) {
            html += '<tr class="prx-skel-row">' +
                '<td><span class="rx-skel rx-skel--sm" style="width:1.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel" style="width:55%"></span><span class="rx-skel rx-skel--sm" style="width:35%"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:2.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--pill" style="width:4.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:2rem;margin:0 auto"></span></td>' +
            '</tr>';
        }
        $('#prTableBody').html(html);
    }

    function emptyHtml(icon, tone, title, text, actionsHtml) {
        return '<div class="rx-empty">' +
            '<div class="rx-empty-icon' + (tone ? ' rx-empty-icon--' + tone : '') + '"><i class="bi ' + icon + '"></i></div>' +
            '<div class="rx-empty-title">' + esc(title) + '</div>' +
            '<div class="rx-empty-text">' + esc(text) + '</div>' +
            (actionsHtml ? '<div class="prx-empty-actions">' + actionsHtml + '</div>' : '') +
        '</div>';
    }

    // The status block (empty / error) replaces the table inside the panel.
    function showStatus(html) {
        $('#prStatus').html(html).prop('hidden', false);
        $('#prTableWrap').prop('hidden', true);
    }

    function hideStatus() {
        $('#prStatus').empty().prop('hidden', true);
        $('#prTableWrap').prop('hidden', false);
    }

    // Empty state: with filters on it offers a reset (the catalogue may
    // not be empty); with defaults it offers adding a producer.
    function showEmpty(f) {
        if (!isDefault(f)) {
            showStatus(emptyHtml('bi-building', '', 'Brak wyników',
                'Żaden producent nie spełnia wybranych kryteriów. Zmień lub wyczyść filtry.',
                '<button type="button" class="btn btn-sm btn-primary js-clear-filters">' +
                    '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'));
        } else {
            showStatus(emptyHtml('bi-building', '', 'Brak aktywnych producentów',
                'Dodaj pierwszego producenta albo zmień status w filtrach.',
                '<a href="' + esc(addUrl) + '" class="btn btn-sm btn-primary">' +
                    '<i class="bi bi-plus-circle"></i> Dodaj producenta</a>'));
        }
    }

    function showError(message) {
        showStatus(emptyHtml('bi-exclamation-octagon', 'danger',
            'Nie udało się wczytać producentów', message,
            '<button type="button" class="btn btn-sm btn-primary js-list-retry">' +
                '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
    }

    // Tile counters from the response; '–' when unknown (error).
    function renderStats(stats) {
        $('#prStats .rx-stat').each(function() {
            const n = stats ? parseInt(stats[$(this).attr('data-stat')], 10) : NaN;
            const known = !isNaN(n);
            $(this).find('[data-stat-value]').text(known ? n : '–');
            $(this).toggleClass('is-zero', known && n === 0);
        });
        $('#prStats').removeClass('is-loading');
    }

    // Results line: range + what the status filter currently shows.
    function renderSummary(f) {
        const $el = $('#prSummary');
        if (totalCount === null) {
            $el.text('Wczytywanie producentów…');
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
            '</strong> ' + producersGen(totalCount) +
            ' <span class="text-faint">·</span> ' + scope);
    }

    // Pager: compact one in the results line, full one under the panel.
    function renderPagination() {
        const $top = $('#prPaginationTop');
        const $bottom = $('#prPaginationBottom');
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
            return '<button type="button" class="btn rx-btn-quiet prx-page-btn" data-action="' + action + '"' +
                ' title="' + title + '" aria-label="' + title + '"' + (disabled ? ' disabled' : '') + '>' +
                '<i class="bi ' + icon + '"></i></button>';
        }

        let items = '';
        for (let p = 1; p <= pages; p++) {
            items += '<a class="dropdown-item prx-page-item' + (p === currentPage ? ' active' : '') +
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
            $top.html('<div class="btn-group btn-group-sm prx-pager-compact" role="group" aria-label="Strony (góra)">' +
                btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart) +
                pageMenu('') +
                btn('next', 'bi-chevron-right', 'Następna strona', atEnd) +
                '</div>');
        } else {
            $top.empty();
        }

        let html = '<div class="rx-pager">' +
            '<div class="rx-pager-info">Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>' +
            totalCount + '</strong> ' + producersGen(totalCount) + '</div>';
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
            const top = $('#producersView .prx-toolbar').offset().top - 16;
            if ($(window).scrollTop() > top) { $('html, body').animate({ scrollTop: top }, 200); }
        }
    }

    function setBusy(on) {
        $('#prRefresh').prop('disabled', on).toggleClass('is-spinning', on);
        $('#prPanel').toggleClass('is-loading', on && hasLoadedOnce);
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
        $('#prStats').addClass('is-loading');
        renderSummary(f);

        listXhr = postAjax('producer-list.php', payload(f))
            .done(function(r) {
                if (seq !== listSeq) { return; }
                if (!r || !r.success) {
                    handleError((r && r.error) || 'Błąd ładowania listy');
                    return;
                }
                totalCount = parseInt(r.total, 10) || 0;
                itemsPerPage = parseInt(r.itemsPerPage, 10) || itemsPerPage;

                // Page past the end (e.g. producers vanished after a refresh).
                if ((r.rows || []).length === 0 && totalCount > 0 && currentPage > 1) {
                    currentPage = 1;
                    loadList();
                    return;
                }

                hasLoadedOnce = true;
                renderStats(r.stats);
                if (totalCount === 0) {
                    $('#prTableBody').empty();
                    showEmpty(f);
                } else {
                    hideStatus();
                    $('#prTableBody').html((r.rows || []).map(renderRow).join(''));
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
        $('#prTableBody').empty();
        showError(message);
        renderStats(null);
        $('#prSummary').text('Lista niedostępna.');
        $('#prPaginationTop, #prPaginationBottom').empty();
    }

    // ============================================================
    // Events
    // ============================================================

    // Status switches (tiles and toolbar buttons alike): flip one, keep
    // at least one lit.
    $('#producersView').on('click', '[data-status-flag]', function() {
        toggleStatus($(this).attr('data-status-flag'));
    });

    // Second-group tile ("Bez artykułów"): set its dimension; a second click on the lit
    // tile puts just that dimension back to its default.
    $('#prStats').on('click', '.rx-stat[data-dim]', function() {
        const dim = $(this).attr('data-dim');
        const value = $(this).attr('data-value');
        setDimension(dim, filterState[dim] === value ? DEFAULTS[dim] : value);
    });

    // Toolbar segments: plain set.
    $('.prx-toolbar').on('click', '.rx-seg [data-dim]', function() {
        setDimension($(this).attr('data-dim'), $(this).attr('data-value'));
    });

    // Search: short delay so we don't query on every keystroke; Enter
    // sends immediately.
    $('#prFilterSearch').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(filtersChanged, SEARCH_DEBOUNCE_MS);
    }).on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            filtersChanged();
        }
    });

    // Refresh keeps the current page.
    $('#prRefresh').on('click', function() { loadList(); });

    // One reset for the link in the results line and the empty state.
    $('#prClear').on('click', resetFilters);
    $('#prStatus').on('click', '.js-clear-filters', resetFilters);
    $('#prStatus').on('click', '.js-list-retry', function() { loadList(); });

    // Pagers (top and bottom share the same handlers).
    $('#prPaginationTop, #prPaginationBottom').on('click', '.prx-page-btn', function() {
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / itemsPerPage)) : 1;
        const fromBottom = $(this).closest('#prPaginationBottom').length > 0;
        const act = $(this).attr('data-action');
        if (act === 'first')     { goToPage(1, fromBottom); }
        else if (act === 'prev') { goToPage(currentPage - 1, fromBottom); }
        else if (act === 'next') { goToPage(currentPage + 1, fromBottom); }
        else if (act === 'last') { goToPage(pages, fromBottom); }
    });
    $('#prPaginationTop, #prPaginationBottom').on('click', '.prx-page-item', function(e) {
        e.preventDefault();
        goToPage(parseInt($(this).attr('data-page'), 10) || 1, $(this).closest('#prPaginationBottom').length > 0);
    });

    // First paint.
    loadList();
});
