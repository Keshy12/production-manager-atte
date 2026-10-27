/**
 * vendor-parts-view.js
 *
 * Admin view for /admin/purchase/vendor-parts. The page is filter-driven
 * and laid out like /admin/purchase/vendors: a row of stat tiles (filter
 * shortcuts), a two-row toolbar, a results line with the top pager, and
 * an AJAX-rendered table (vp-list.php) in a panel with the full pager
 * below. There is no add/edit form on this page.
 *
 * Adding and editing happen on a dedicated page — see
 * /admin/purchase/vendor-parts/edit (rendered by
 * edit/vendor-part-edit-view.php). Each row's pencil button is a plain
 * anchor that navigates there.
 *
 * Filter state:
 *   - two "dimensions" live in `filterState` (status, hasPacks) and are
 *     shown by BOTH the stat tiles and the toolbar segmented buttons —
 *     syncFilterUi() paints both from that one object, so they cannot
 *     disagree;
 *   - search, the date range and the Dostawca / Producent / Część pickers
 *     live in their controls (single-select; the empty-value first option
 *     means "all").
 *   Defaults (isDefault): status = active, everything else off.
 *
 * Tile semantics: a tile sets exactly one dimension (data-dim /
 * data-value). Clicking an already-active tile resets only that dimension
 * to its default. Tile counts come from `stats` in the vp-list.php
 * response: each is the count under the CURRENT filters with only that
 * tile's dimension overridden, i.e. what clicking the tile would return.
 */

$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/VendorParts/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/vendor-parts/edit?id=";
    const addUrl   = window.location.origin + "/atte_ms_new/admin/purchase/vendor-parts/edit";

    const DEFAULTS = { status: 'active', hasPacks: 'all' };
    const SEARCH_DEBOUNCE_MS = 300;

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // "z 1 artykułu" / "z 5 artykułów" (dopełniacz).
    function itemsGen(n) {
        return n === 1 ? 'artykułu' : 'artykułów';
    }

    // Format a float without trailing zeros (e.g. 10.000 -> "10").
    function fmtQty(n) {
        const f = parseFloat(n);
        if (isNaN(f)) return String(n == null ? '—' : n);
        return Number.isInteger(f) ? f.toString() : parseFloat(f.toFixed(4)).toString();
    }

    // Render the packs cell — small badges followed by the unit once, so
    // the quantity and its unit read together. Empty state shows a muted
    // em-dash so the column doesn't collapse.
    function renderPacksHtml(packs, unitName) {
        if (!Array.isArray(packs) || packs.length === 0) {
            return '<span class="vp-none">—</span>';
        }
        const badges = packs.map(p => '<span class="vp-pack-badge">' + esc(fmtQty(p)) + '</span>').join('');
        return badges + (unitName ? ' <span class="vp-pack-unit">' + esc(unitName) + '</span>' : '');
    }

    // ============================================================
    // State
    // ============================================================
    // itemsPerPage comes from the server response (vp-list.php) so the
    // client and backend share one source of truth.
    let itemsPerPage = 10;
    let currentPage = 1;
    let totalCount  = null;      // null = unknown yet (loading)
    let listXhr = null;
    let listSeq = 0;
    let hasLoadedOnce = false;
    let searchTimer = null;
    const filterState = $.extend({}, DEFAULTS);

    // Pickers: id of the <select>, id of its .rx-tool cell.
    const PICKERS = {
        vendors:   { sel: '#vpFilterVendors',   cell: '#vpVendorCell' },
        producers: { sel: '#vpFilterProducers', cell: '#vpProducerCell' },
        parts:     { sel: '#vpFilterParts',     cell: '#vpPartCell' },
    };

    // Surface the ?updated=N / ?created=N success flashes sent back from
    // the edit page. Show them once, then strip from URL via
    // history.replaceState so a refresh doesn't re-show the toast.
    const params = new URLSearchParams(window.location.search);
    if (params.has('updated')) {
        const updatedId = params.get('updated');
        setAlert('Zapisano zmiany (artykuł #' + esc(updatedId) + ').', 'success');
        params.delete('updated');
    }
    if (params.has('created')) {
        const createdId = params.get('created');
        setAlert('Artykuł #' + esc(createdId) + ' został dodany.', 'success');
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

    // Picker value as a string; '' = "all" (the empty first option, or
    // nothing selected).
    function pickerVal(key) {
        const v = $(PICKERS[key].sel).val();
        return v ? String(v) : '';
    }

    // Current filters as the UI sees them ('all' | 'used' | 'unused' for
    // hasPacks).
    function gatherFilters() {
        return {
            status:    filterState.status,
            hasPacks:  filterState.hasPacks,
            vendor:    pickerVal('vendors'),
            producer:  pickerVal('producers'),
            part:      pickerVal('parts'),
            search:    ($('#vpFilterSearch').val() || '').trim(),
            dateFrom:  $('#vpFilterDateFrom').val() || '',
            dateTo:    $('#vpFilterDateTo').val()   || '',
        };
    }

    // True when nothing differs from the page defaults.
    function isDefault(f) {
        return f.status === DEFAULTS.status
            && f.hasPacks === DEFAULTS.hasPacks
            && f.vendor === ''
            && f.producer === ''
            && f.part === ''
            && f.search === ''
            && f.dateFrom === ''
            && f.dateTo === '';
    }

    // Wire format for hasPacks: 'used' -> 'true', 'unused' -> 'false',
    // 'all' -> omitted (the repo treats it as no-op).
    function triBool(raw) {
        if (raw === 'used')   return 'true';
        if (raw === 'unused') return 'false';
        return null;
    }

    function payload(f) {
        // Single-select pickers are wrapped into 1-element arrays so the
        // endpoint's IN (?) placeholder logic stays unchanged; empty
        // arrays are dropped by jQuery (= no filter).
        const data = {
            vendorIds:   f.vendor   ? [f.vendor]   : [],
            producerIds: f.producer ? [f.producer] : [],
            partIds:     f.part     ? [f.part]     : [],
            status:      f.status,
            search:      f.search,
            dateFrom:    f.dateFrom,
            dateTo:      f.dateTo,
            page:        currentPage,
        };
        const hp = triBool(f.hasPacks);
        if (hp !== null) { data.hasPacks = hp; }
        return data;
    }

    // Paint every control that mirrors the filters: toolbar segments,
    // stat tiles, picker cells, date range highlight and the
    // "Wyczyść filtry" link.
    function syncFilterUi(f) {
        $('.vpx-toolbar .rx-seg [data-dim]').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
        $('#vpStats .rx-stat').each(function() {
            const on = f[$(this).attr('data-dim')] === $(this).attr('data-value');
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
        $(PICKERS.vendors.cell).toggleClass('is-active', f.vendor !== '');
        $(PICKERS.producers.cell).toggleClass('is-active', f.producer !== '');
        $(PICKERS.parts.cell).toggleClass('is-active', f.part !== '');
        $('#vpDateRange').toggleClass('is-active', f.dateFrom !== '' || f.dateTo !== '');
        $('#vpClear').toggleClass('d-none', isDefault(f));
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

    // ============================================================
    // Vendor <-> producer cascade
    // ============================================================
    // Picking a vendor narrows the producer picker to producers that
    // vendor sells, and vice versa. Full lists are cached once on page
    // load so clearing one side restores the other without a request.
    let fullVendors   = [];
    let fullProducers = [];
    const cascadeSeq = { vendors: 0, producers: 0 };

    function snapshotOptions(selector) {
        return $(selector).find('option').map(function() {
            return { id: $(this).attr('value'), name: $(this).text() };
        }).get().filter(function(it) { return it.id !== undefined && it.id !== ''; });
    }

    // Replace the options of a picker with `items`, keeping the current
    // pick only if it still exists in `items`. The empty "all" option
    // always stays first. Does NOT fire `change` (jQuery .val() and
    // selectpicker('refresh') are both non-firing), so no recursion.
    function rebuildPicker(key, items) {
        const $sel = $(PICKERS[key].sel);
        const prev = pickerVal(key);
        const keep = items.some(function(it) { return String(it.id) === prev; }) ? prev : '';

        let opts = '<option value="" data-tokens="wszyscy">' + esc($sel.attr('data-all-label') || '') + '</option>';
        items.forEach(function(it) {
            opts += '<option value="' + esc(it.id) + '">' + esc(it.name) + '</option>';
        });
        $sel.html(opts).val(keep);
        $sel.selectpicker('refresh');
    }

    // `ids` = what the user just picked on the source side. Empty source
    // -> target reverts to its full list (no request). If the target
    // already has a pick it wins over the cascade and is left alone: the
    // cascade is only a hint for the OTHER side while it is still empty.
    function cascadeNarrow(mode, srcKey, targetKey, fullItems) {
        const seq = ++cascadeSeq[targetKey];
        const id = pickerVal(srcKey);

        if (id === '') {
            rebuildPicker(targetKey, fullItems);
            syncFilterUi(gatherFilters());
            return;
        }
        if (pickerVal(targetKey) !== '') { return; }

        postAjax('vp-filter-options.php', { mode: mode, ids: [id] })
            .done(function(r) {
                if (seq !== cascadeSeq[targetKey]) { return; }   // superseded
                if (r && r.success) {
                    rebuildPicker(targetKey, r.items || []);
                    syncFilterUi(gatherFilters());
                } else {
                    setAlert(esc((r && r.error) || 'Błąd ładowania opcji filtra'), 'danger');
                }
            })
            .fail(function() { setAlert('Błąd komunikacji z serwerem', 'danger'); });
    }

    // Back to the page defaults (same state as on first load), including
    // the pickers and their full option lists.
    function resetFilters() {
        $.extend(filterState, DEFAULTS);
        $(PICKERS.vendors.sel).val('');
        $(PICKERS.producers.sel).val('');
        rebuildPicker('vendors',   fullVendors);
        rebuildPicker('producers', fullProducers);
        $(PICKERS.parts.sel).val('').selectpicker('refresh');
        $('#vpFilterSearch').val('');
        $('#vpFilterDateFrom').val('');
        $('#vpFilterDateTo').val('');
        ++cascadeSeq.vendors;
        ++cascadeSeq.producers;
        filtersChanged();
    }

    // ============================================================
    // Rendering
    // ============================================================

    function renderRow(vp) {
        const isActive = vp.isActive === true || vp.isActive === 1 || vp.isActive === '1';
        // Inactive rows: muted background + grey left edge (vendor-parts-ui.css),
        // the dim text rules and the grey status badge.
        const rowClass = isActive ? '' : 'vp-row--inactive';

        // Artykuł cell — vendor part number is the primary line (that is
        // what the admin recognises a row by), our catalogue part name is
        // the muted line below. The producer number is shown only when it
        // differs from the vendor one. Fallback to the part name when the
        // vendor number is somehow empty.
        const vn = vp.vendorPartNo || '';
        const pn = vp.producerPartNo || '';
        const partName = vp.partName || '';
        let artykulCell;
        if (vn) {
            artykulCell = '<span class="vp-code" title="' + esc(vn) + '">' + esc(vn) + '</span>';
            if (partName) {
                artykulCell += '<span class="vp-sub" title="' + esc(partName) + '">' + esc(partName) + '</span>';
            }
        } else {
            artykulCell = '<span class="vp-name">' + esc(partName || '—') + '</span>';
        }
        if (pn && pn !== vn) {
            artykulCell += '<span class="vp-code vp-code--sub" title="' + esc('Nr producenta: ' + pn) + '">prod.: ' + esc(pn) + '</span>';
        }

        // Comment: free text, mostly empty — shown as the last line of
        // the Artykuł cell: bi-journal-text icon (full text in title)
        // followed by the comment text, truncated with an ellipsis by CSS.
        const rawCmt = (vp.comment == null ? '' : String(vp.comment)).trim();
        if (rawCmt) {
            artykulCell += '<span class="vp-cmt">' +
                '<i class="bi bi-journal-text vp-note" title="' + esc(rawCmt) + '"></i>' +
                '<span class="vp-cmt-text" title="' + esc(rawCmt) + '">' + esc(rawCmt) + '</span>' +
            '</span>';
        }

        // Dostawca cell — vendor name on the main line, producer name as a
        // muted sub-line (omitted when missing).
        let dostawcaCell = '<span class="vp-name">' + esc(vp.vendorName || '—') + '</span>';
        if (vp.producerName) {
            dostawcaCell += '<span class="vp-sub">' + esc(vp.producerName) + '</span>';
        }

        const statusBadge = isActive
            ? '<span class="rx-badge rx-badge--green">Aktywny</span>'
            : '<span class="rx-badge">Nieaktywny</span>';

        const id = esc(vp.id);
        const editTitle = 'Edytuj artykuł #' + id;

        return '<tr class="' + rowClass + '" data-id="' + id + '">' +
            '<td class="vp-col-id">' + id + '</td>' +
            '<td>' + artykulCell + '</td>' +
            '<td>' + dostawcaCell + '</td>' +
            '<td>' + renderPacksHtml(vp.packQuantities, vp.unitName) + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm rx-btn-quiet" href="' + editBase + id + '"' +
                    ' title="' + editTitle + '" aria-label="' + editTitle + '">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    // First load: shimmering placeholder rows instead of an empty panel.
    function skeletonHtml() {
        let html = '';
        for (let i = 0; i < 6; i++) {
            html += '<tr class="vpx-skel-row">' +
                '<td><span class="rx-skel rx-skel--sm" style="width:1.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel" style="width:55%"></span><span class="rx-skel rx-skel--sm" style="width:35%"></span></td>' +
                '<td><span class="rx-skel" style="width:60%"></span><span class="rx-skel rx-skel--sm" style="width:40%"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:4rem"></span></td>' +
                '<td><span class="rx-skel rx-skel--pill" style="width:4.5rem;margin:0 auto"></span></td>' +
                '<td><span class="rx-skel rx-skel--sm" style="width:2rem;margin:0 auto"></span></td>' +
            '</tr>';
        }
        $('#vpTableBody').html(html);
    }

    function emptyHtml(icon, tone, title, text, actionsHtml) {
        return '<div class="rx-empty">' +
            '<div class="rx-empty-icon' + (tone ? ' rx-empty-icon--' + tone : '') + '"><i class="bi ' + icon + '"></i></div>' +
            '<div class="rx-empty-title">' + esc(title) + '</div>' +
            '<div class="rx-empty-text">' + esc(text) + '</div>' +
            (actionsHtml ? '<div class="vpx-empty-actions">' + actionsHtml + '</div>' : '') +
        '</div>';
    }

    // The status block (empty / error) replaces the table inside the panel.
    function showStatus(html) {
        $('#vpStatus').html(html).prop('hidden', false);
        $('#vpTableWrap').prop('hidden', true);
    }

    function hideStatus() {
        $('#vpStatus').empty().prop('hidden', true);
        $('#vpTableWrap').prop('hidden', false);
    }

    // Empty state: with filters on it offers a reset (the catalogue may
    // not be empty); with defaults it offers adding an article.
    function showEmpty(f) {
        if (!isDefault(f)) {
            showStatus(emptyHtml('bi-box-seam', '', 'Brak wyników',
                'Żaden artykuł nie spełnia wybranych kryteriów. Zmień lub wyczyść filtry.',
                '<button type="button" class="btn btn-sm btn-primary js-clear-filters">' +
                    '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'));
        } else {
            showStatus(emptyHtml('bi-box-seam', '', 'Brak aktywnych artykułów',
                'Dodaj pierwszy artykuł albo zmień status w filtrach.',
                '<a href="' + addUrl + '" class="btn btn-sm btn-primary">' +
                    '<i class="bi bi-plus-circle"></i> Dodaj artykuł</a>'));
        }
    }

    function showError(message) {
        showStatus(emptyHtml('bi-exclamation-octagon', 'danger',
            'Nie udało się wczytać artykułów', message,
            '<button type="button" class="btn btn-sm btn-primary js-list-retry">' +
                '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
    }

    // Tile counters from the response; '–' when unknown (error).
    function renderStats(stats) {
        $('#vpStats .rx-stat').each(function() {
            const n = stats ? parseInt(stats[$(this).attr('data-stat')], 10) : NaN;
            const known = !isNaN(n);
            $(this).find('[data-stat-value]').text(known ? n : '–');
            $(this).toggleClass('is-zero', known && n === 0);
        });
        $('#vpStats').removeClass('is-loading');
    }

    // Results line: range + what the status filter currently shows.
    function renderSummary(f) {
        const $el = $('#vpSummary');
        if (totalCount === null) {
            $el.text('Wczytywanie artykułów…');
            return;
        }
        if (totalCount === 0) {
            $el.html((isDefault(f) ? '' : '<i class="bi bi-funnel-fill"></i> ') + 'Brak wyników');
            return;
        }
        const start = (currentPage - 1) * itemsPerPage + 1;
        const end   = Math.min(currentPage * itemsPerPage, totalCount);
        const scope = f.status === 'active'   ? 'tylko aktywne'
                    : f.status === 'inactive' ? 'tylko nieaktywne'
                    : 'aktywne i nieaktywne';
        $el.html((isDefault(f) ? '' : '<i class="bi bi-funnel-fill"></i> ') +
            'Wyświetlanie <strong>' + start + '–' + end + '</strong> z <strong>' + totalCount +
            '</strong> ' + itemsGen(totalCount) +
            ' <span class="vpx-sep text-faint">·</span> ' + scope);
    }

    // Pager: compact one in the results line, full one under the panel.
    function renderPagination() {
        const $top = $('#vpPaginationTop');
        const $bottom = $('#vpPaginationBottom');
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
            return '<button type="button" class="btn rx-btn-quiet vpx-page-btn" data-action="' + action + '"' +
                ' title="' + title + '" aria-label="' + title + '"' + (disabled ? ' disabled' : '') + '>' +
                '<i class="bi ' + icon + '"></i></button>';
        }

        let items = '';
        for (let p = 1; p <= pages; p++) {
            items += '<a class="dropdown-item vpx-page-item' + (p === currentPage ? ' active' : '') +
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
            $top.html('<div class="btn-group btn-group-sm vpx-pager-compact" role="group" aria-label="Strony (góra)">' +
                btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart) +
                pageMenu('') +
                btn('next', 'bi-chevron-right', 'Następna strona', atEnd) +
                '</div>');
        } else {
            $top.empty();
        }

        let html = '<div class="rx-pager">' +
            '<div class="rx-pager-info">Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>' +
            totalCount + '</strong> ' + itemsGen(totalCount) + '</div>';
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
            const top = $('#vendorPartsView .vpx-toolbar').offset().top - 16;
            if ($(window).scrollTop() > top) { $('html, body').animate({ scrollTop: top }, 200); }
        }
    }

    function setBusy(on) {
        $('#vpRefresh').prop('disabled', on).toggleClass('is-spinning', on);
        $('#vpPanel').toggleClass('is-loading', on && hasLoadedOnce);
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
        $('#vpStats').addClass('is-loading');
        renderSummary(f);

        listXhr = postAjax('vp-list.php', payload(f))
            .done(function(r) {
                if (seq !== listSeq) { return; }
                if (!r || !r.success) {
                    handleError((r && r.error) || 'Błąd ładowania listy');
                    return;
                }
                totalCount = parseInt(r.total, 10) || 0;
                itemsPerPage = parseInt(r.itemsPerPage, 10) || itemsPerPage;

                // Page past the end (e.g. rows vanished after a refresh).
                if ((r.rows || []).length === 0 && totalCount > 0 && currentPage > 1) {
                    currentPage = 1;
                    loadList();
                    return;
                }

                hasLoadedOnce = true;
                renderStats(r.stats);
                if (totalCount === 0) {
                    $('#vpTableBody').empty();
                    showEmpty(f);
                } else {
                    hideStatus();
                    $('#vpTableBody').html((r.rows || []).map(renderRow).join(''));
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
        $('#vpTableBody').empty();
        showError(message);
        renderStats(null);
        $('#vpSummary').text('Lista niedostępna.');
        $('#vpPaginationTop, #vpPaginationBottom').empty();
    }

    // ============================================================
    // Events
    // ============================================================

    // Tiles: set one dimension; a second click on the active tile puts
    // just that dimension back to its default.
    $('#vpStats').on('click', '.rx-stat', function() {
        const dim = $(this).attr('data-dim');
        const value = $(this).attr('data-value');
        setDimension(dim, filterState[dim] === value ? DEFAULTS[dim] : value);
    });

    // Toolbar segments: plain set.
    $('.vpx-toolbar').on('click', '.rx-seg [data-dim]', function() {
        setDimension($(this).attr('data-dim'), $(this).attr('data-value'));
    });

    // Pickers. Vendor and producer cascade each other; the list reloads
    // right away (the cascade only rewrites the OTHER picker's options
    // and never changes the current filters).
    $(PICKERS.vendors.sel).on('change', function() {
        cascadeNarrow('producers_for_vendors', 'vendors', 'producers', fullProducers);
        filtersChanged();
    });
    $(PICKERS.producers.sel).on('change', function() {
        cascadeNarrow('vendors_for_producers', 'producers', 'vendors', fullVendors);
        filtersChanged();
    });
    $(PICKERS.parts.sel).on('change', filtersChanged);

    // Search: short delay so we don't query on every keystroke; Enter
    // sends immediately.
    $('#vpFilterSearch').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(filtersChanged, SEARCH_DEBOUNCE_MS);
    }).on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            filtersChanged();
        }
    });

    $('#vpFilterDateFrom, #vpFilterDateTo').on('change', filtersChanged);

    // Refresh keeps the current page.
    $('#vpRefresh').on('click', function() { loadList(); });

    // One reset for the link in the results line and the empty state.
    $('#vpClear').on('click', resetFilters);
    $('#vpStatus').on('click', '.js-clear-filters', resetFilters);
    $('#vpStatus').on('click', '.js-list-retry', function() { loadList(); });

    // Pagers (top and bottom share the same handlers).
    $('#vpPaginationTop, #vpPaginationBottom').on('click', '.vpx-page-btn', function() {
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / itemsPerPage)) : 1;
        const fromBottom = $(this).closest('#vpPaginationBottom').length > 0;
        const act = $(this).attr('data-action');
        if (act === 'first')     { goToPage(1, fromBottom); }
        else if (act === 'prev') { goToPage(currentPage - 1, fromBottom); }
        else if (act === 'next') { goToPage(currentPage + 1, fromBottom); }
        else if (act === 'last') { goToPage(pages, fromBottom); }
    });
    $('#vpPaginationTop, #vpPaginationBottom').on('click', '.vpx-page-item', function(e) {
        e.preventDefault();
        goToPage(parseInt($(this).attr('data-page'), 10) || 1, $(this).closest('#vpPaginationBottom').length > 0);
    });

    // First paint — capture the full option lists BEFORE any cascade can
    // mutate the DOM, then load.
    fullVendors   = snapshotOptions(PICKERS.vendors.sel);
    fullProducers = snapshotOptions(PICKERS.producers.sel);
    loadList();
});
