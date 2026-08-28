/**
 * vendor-parts-view.js
 *
 * Admin view for /admin/purchase/vendor-parts. After the redesign this
 * page is filter-driven: no add form, just a filter card, an AJAX-rendered
 * table, and pagination.
 *
 * The edit flow is a dedicated page now — see /admin/purchase/vendor-parts/edit
 * (wired in index.php and rendered by edit/vendor-part-edit-view.php). Each row's
 * "Edytuj" button is a plain anchor that navigates there. The legacy modal
 * + vp-get.php / vp-update.php / vp-search-vendors.php endpoints and the
 * search wiring for them have been removed; vp-search-producers.php,
 * vp-search-parts.php, vp-search-units.php are still consumed by the
 * Vendors page's add-VP modal and remain in place.
 */

$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/VendorParts/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/vendor-parts/edit?id=";

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function setAlert(message, kind) {
        const $box = $('#alertContainer');
        if (!message) { $box.empty(); return; }
        $box.html(
            '<div class="alert alert-' + (kind || 'info') + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>' +
            '</div>'
        );
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // Format a float without trailing zeros (e.g. 10.000 -> "10").
    function fmtQty(n) {
        const f = parseFloat(n);
        if (isNaN(f)) return String(n == null ? '—' : n);
        return Number.isInteger(f) ? f.toString() : parseFloat(f.toFixed(4)).toString();
    }

    // Render the packs cell — comma-separated small badges. Empty state
    // shows a muted em-dash so the column doesn't collapse.
    function renderPacksHtml(packs) {
        if (!Array.isArray(packs) || packs.length === 0) {
            return '<span class="vp-pack-badge vp-pack-badge--empty">—</span>';
        }
        return packs.map(p => '<span class="vp-pack-badge">' + esc(fmtQty(p)) + '</span>').join('');
    }

    // ============================================================
    // Filter / pagination / table-render logic
    // ============================================================
    // itemsPerPage comes from the server response (vp-list.php) so the
    // client and backend share one source of truth — see line where
    // `r.itemsPerPage` is assigned.
    let itemsPerPage = 10;
    let currentPage = 1;
    let totalCount  = 0;
    let isLoading   = false;

    function getStatus(name) {
        return $('input[name="' + name + '"]:checked').val() || 'all';
    }

    function setStatus(name, value) {
        const $radios = $('input[name="' + name + '"]');
        $radios.filter('[value="' + value + '"]').prop('checked', true);
        // Sync the segmented button labels (Bootstrap 4's .active class).
        $('input[name="' + name + '"]').each(function() {
            $(this).closest('label.btn').toggleClass('active', $(this).val() === value);
        });
    }

    // Surface the ?updated=N success flash sent back from the edit
    // page (vendor-part-edit-save.php redirects to the listing with
    // that param after a successful save). Show it once, then strip
    // it from the URL via history.replaceState so a refresh doesn't
    // re-show the toast.
    const params = new URLSearchParams(window.location.search);
    if (params.has('updated')) {
        const updatedId = params.get('updated');
        setAlert('Zapisano zmiany (artykuł #' + updatedId + ').', 'success');
        params.delete('updated');
        const newQuery = params.toString();
        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
        window.history.replaceState({}, '', newUrl);
    }

    function gatherFilters() {
        // Vendor, Producer, and Part are all single-select — .val() returns
        // a string or null. Wrap each to a 1-elt array (or empty) so the
        // vp-list.php payload and buildSearchWhere()'s IN (?) placeholder
        // logic stay unchanged.
        const vendorVal   = $('#vpFilterVendors').val();
        const producerVal = $('#vpFilterProducers').val();
        const partVal     = $('#vpFilterParts').val();
        return {
            vendorIds:   vendorVal   ? [vendorVal]   : [],
            producerIds: producerVal ? [producerVal] : [],
            partIds:     partVal     ? [partVal]     : [],
            status:      getStatus('vpFilterStatus'),
            search:      $('#vpFilterSearch').val()    || '',
            dateFrom:    $('#vpFilterDateFrom').val()  || '',
            dateTo:      $('#vpFilterDateTo').val()    || '',
            page:        currentPage,
        };
    }

    function renderRow(vp) {
        const isActive = vp.isActive === true || vp.isActive === 1 || vp.isActive === '1';
        // Inactive rows are conveyed by the table-secondary tint + the
        // dim text CSS rule. Active rows are plain (white background).
        const rowClass = isActive ? '' : 'vp-row--inactive table-secondary';

        // Artykuł cell — part name + merged-or-separate vendor/producer
        // numbers. Mirrors the cart-view.js part-cell build (lines
        // 1230–1238 there) so both pages read identically.
        let artykulCell = esc(vp.partName || '—');
        const vn = vp.vendorPartNo || '';
        const pn = vp.producerPartNo || '';
        if (vn && pn && vn === pn) {
            artykulCell += '<div><small class="text-muted">Nr dost./prod.: <span class="font-weight-bold">' + esc(vn) + '</span></small></div>';
        } else {
            if (vn) artykulCell += '<div><small class="text-muted">Nr dost.: <span class="font-weight-bold">' + esc(vn) + '</span></small></div>';
            if (pn) artykulCell += '<div><small class="text-muted">Nr prod.: <span class="font-weight-bold">' + esc(pn) + '</span></small></div>';
        }

        // Dostawca cell — vendor name on the main line, producer name as a
        // muted sub-line. Producer sub-line is suppressed when missing so
        // the cell stays compact for vendor-only rows.
        let dostawcaCell = esc(vp.vendorName || '—');
        if (vp.producerName) {
            dostawcaCell += '<div><small class="text-muted">' + esc(vp.producerName) + '</small></div>';
        }

        // Komentarz cell — vp.comment rendered with the cart's journal
        // glyph. Long comments are truncated with an ellipsis so a row
        // never balloons vertically; the full text stays accessible via
        // the native title tooltip. Truncation threshold picked to keep
        // ~3-4 wrapped lines visible at the column's min-width.
        const COMMENT_TRUNCATE_AT = 200;
        const rawCmt = (vp.comment == null ? '' : String(vp.comment)).trim();
        let komentarzCell;
        if (!rawCmt) {
            komentarzCell = '<span class="text-muted">—</span>';
        } else if (rawCmt.length > COMMENT_TRUNCATE_AT) {
            const visible = esc(rawCmt.slice(0, COMMENT_TRUNCATE_AT).trimEnd()) + '…';
            komentarzCell = '<small class="text-muted" title="' + esc(rawCmt) + '"><i class="bi bi-journal-text"></i> ' + visible + '</small>';
        } else {
            komentarzCell = '<small class="text-muted"><i class="bi bi-journal-text"></i> ' + esc(rawCmt) + '</small>';
        }

        return '<tr class="' + rowClass + '" data-id="' + vp.id + '">' +
            '<td class="vp-col-id text-center">' + vp.id + '</td>' +
            '<td>' + artykulCell + '</td>' +
            '<td>' + dostawcaCell + '</td>' +
            '<td>' + esc(vp.unitName || '—') + '</td>' +
            '<td>' + renderPacksHtml(vp.packQuantities) + '</td>' +
            '<td>' + komentarzCell + '</td>' +
            '<td>' +
                '<a class="btn btn-sm btn-warning" href="' + editBase + vp.id + '">' +
                    '<i class="bi bi-pencil"></i> Edytuj' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    function renderEmptyRow(message) {
        return '<tr><td colspan="7" class="text-center text-muted py-4">' +
            esc(message) + '</td></tr>';
    }

    function renderPagination() {
        const pages = Math.max(1, Math.ceil(totalCount / itemsPerPage));
        const start = totalCount === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1;
        const end   = Math.min(currentPage * itemsPerPage, totalCount);
        const hasPrev = currentPage > 1;
        const hasNext = currentPage < pages;

        const html =
            '<div class="d-flex flex-column align-items-center my-2">' +
                '<div class="text-muted small mb-2">Wyświetlanie <strong>' + start + '–' + end +
                '</strong> z <strong>' + totalCount + '</strong> elementów</div>' +
                '<div class="btn-group btn-group-sm" role="group">' +
                    '<button class="btn btn-outline-primary vp-page-btn" data-action="first" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-double-left"></i></button>' +
                    '<button class="btn btn-outline-primary vp-page-btn" data-action="prev" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-left"></i></button>' +
                    '<div class="btn-group">' +
                        '<button type="button" class="btn btn-primary dropdown-toggle" ' +
                            'data-toggle="dropdown">' + currentPage + '</button>' +
                        '<div class="dropdown-menu vp-page-dropdown" ' +
                            'style="max-height: 300px; overflow-y: auto;"></div>' +
                    '</div>' +
                    '<button class="btn btn-outline-primary vp-page-btn" data-action="next" ' +
                        (hasNext ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-right"></i></button>' +
                '</div>' +
            '</div>';

        $('#vpPaginationTop, #vpPaginationBottom').html(html);

        // Build page dropdown items
        let pageItems = '';
        for (let i = 1; i <= pages; i++) {
            pageItems += '<a class="dropdown-item vp-page-jump' +
                (i === currentPage ? ' active' : '') +
                '" href="#" data-page="' + i + '">' + i + '</a>';
        }
        $('.vp-page-dropdown').html(pageItems);

        $('.vp-page-btn').off('click').on('click', function() {
            const act = $(this).data('action');
            if (act === 'first')         { currentPage = 1; }
            else if (act === 'prev' && currentPage > 1)   { currentPage--; }
            else if (act === 'next' && currentPage < pages) { currentPage++; }
            else { return; }
            loadList(true);
        });
        $('.vp-page-jump').off('click').on('click', function(e) {
            e.preventDefault();
            currentPage = parseInt($(this).data('page'), 10) || 1;
            loadList(true);
        });
    }

    function loadList(skipPageReset) {
        if (isLoading) return;
        if (!skipPageReset) { currentPage = 1; }

        isLoading = true;
        $('#vpSpinner').prop('hidden', false);
        setAlert('', null); // clear stale alerts on every reload

        const filters = gatherFilters();

        postAjax('vp-list.php', filters)
            .done(function(r) {
                if (!r.success) {
                    setAlert(r.error || 'Błąd ładowania listy', 'danger');
                    $('#vpTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
                    totalCount = 0;
                    $('#vpCountBadge').text('0');
                    renderPagination();
                    return;
                }
                totalCount = r.total || 0;
                itemsPerPage = r.itemsPerPage || itemsPerPage;
                $('#vpCountBadge').text(totalCount);

                if (totalCount === 0) {
                    $('#vpTableBody').html(renderEmptyRow('Brak artykułów spełniających kryteria'));
                } else {
                    const html = (r.rows || []).map(renderRow).join('');
                    $('#vpTableBody').html(html);
                }
                renderPagination();
            })
            .fail(function() {
                setAlert('Błąd komunikacji z serwerem', 'danger');
                $('#vpTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
            })
            .always(function() {
                isLoading = false;
                $('#vpSpinner').prop('hidden', true);
            });
    }

    // ============================================================
    // Filter card / quick controls wiring
    // ============================================================

    // ---------- Cascade filter: Dostawca ↔ Producent ----------
    // Cache the full vendor / producer lists once on page load so we can
    // restore them on the "Wyczyść" button without an extra AJAX round-
    // trip, and so we can short-circuit the cascade when the user clears
    // one side (per spec: empty selection → revert to full list).
    let vpFullVendors   = [];
    let vpFullProducers = [];

    function snapshotFullLists() {
        vpFullVendors = $('#vpFilterVendors').find('option').map(function() {
            return { id: $(this).attr('value'), name: $(this).text() };
        }).get();
        vpFullProducers = $('#vpFilterProducers').find('option').map(function() {
            return { id: $(this).attr('value'), name: $(this).text() };
        }).get();
    }

    // Replace the options of a selectpicker with `items`, preserving only
    // the previously-selected values that still exist in `items`.
    // Does NOT fire a `change` event (jQuery's .val() + selectpicker
    // refresh are both non-firing), so cascade updates don't recurse.
    // Works for both single-select (.val() returns a string or null) and
    // multi-select (.val() returns an array) pickers — normalise to an
    // array internally so the filter logic is shape-agnostic. jQuery's
    // .val(array) accepts a 1-element array on a single-select <select>
    // (it sets the matching option), and an empty array clears it.
    function rebuildSelectKeepValid(selector, items) {
        const $sel = $(selector);
        const rawPrev = $sel.val();
        const prevList = rawPrev == null
            ? []
            : (Array.isArray(rawPrev) ? rawPrev : [rawPrev]);
        const validIds  = new Set(items.map(function(it) { return String(it.id); }));
        const preserved = prevList.filter(function(id) { return validIds.has(String(id)); });

        let opts = '';
        items.forEach(function(it) {
            opts += '<option value="' + esc(it.id) + '">' + esc(it.name) + '</option>';
        });
        $sel.html(opts).val(preserved);
        $sel.selectpicker('refresh');
    }

    function cascadeNarrow(mode, ids, targetSelector, fullItems, after) {
        // Empty source selection → revert the target to the cached full
        // list, no AJAX. rebuildSelectKeepValid preserves any target
        // selection that exists in the full list.
        if (!ids || ids.length === 0) {
            rebuildSelectKeepValid(targetSelector, fullItems);
            if (after) after();
            return;
        }
        // If the target picker already has a value, leave it alone. The
        // existing selection wins over the cascade — narrowing the target
        // would risk dropping the value via rebuildSelectKeepValid if the
        // newly-picked source has no overlap with the previously-picked
        // target. The cascade is only a *picker hint* for the OTHER side
        // when it's still empty; once both sides have a value, the user is
        // in control and can clear either side explicitly if they want a
        // different narrowing.
        if ($(targetSelector).val()) {
            if (after) after();
            return;
        }
        postAjax('vp-filter-options.php', { mode: mode, ids: ids })
            .done(function(r) {
                if (r.success) {
                    rebuildSelectKeepValid(targetSelector, r.items || []);
                    if (after) after();
                } else {
                    setAlert(r.error || 'Błąd ładowania opcji filtra', 'danger');
                }
            })
            .fail(function() { setAlert('Błąd komunikacji z serwerem', 'danger'); });
    }

    // ---------- Filter change wiring ----------
    // Vendor / Producer cascade each other. Every user change still
    // reloads the table from page 1.
    $('#vpFilterVendors').on('change', function() {
        // Single-select: .val() returns a string or null. Wrap to a 1-elt
        // array so the endpoint's `ids` param (and the repository's IN(?)
        // placeholder count) keep working unchanged.
        const raw = $(this).val();
        const ids = raw ? [raw] : [];
        cascadeNarrow('producers_for_vendors', ids, '#vpFilterProducers', vpFullProducers, function() {
            loadList();
        });
    });
    $('#vpFilterProducers').on('change', function() {
        const raw = $(this).val();
        const ids = raw ? [raw] : [];
        cascadeNarrow('vendors_for_producers', ids, '#vpFilterVendors', vpFullVendors, function() {
            loadList();
        });
    });
    $('#vpFilterParts').on('change', function() {
        loadList();
    });
    $('#vpFilterSearch').on('input', function() {
        loadList();
    });
    $('#vpFilterDateFrom, #vpFilterDateTo').on('change', function() { loadList(); });

    // Status segmented buttons trigger reload.
    function bindStatusGroup(groupName) {
        $('input[name="' + groupName + '"]').on('change', function() {
            loadList();
        });
    }
    bindStatusGroup('vpFilterStatus');

    // Per-section "Wyczyść" buttons.
    $('#vpClearVendorProducer').on('click', function() {
        // Reset BOTH pickers back to the full list cached at page load
        // AND clear any current selection (per spec: shared Wyczyść must
        // refresh both sides and drop the active pick). Clearing .val()
        // before the rebuild keeps rebuildSelectKeepValid from re-pinning
        // a value that's still in the full list.
        $('#vpFilterVendors').val(null);
        $('#vpFilterProducers').val(null);
        rebuildSelectKeepValid('#vpFilterVendors',   vpFullVendors);
        rebuildSelectKeepValid('#vpFilterProducers', vpFullProducers);
        loadList();
    });
    $('#vpClearPart').on('click', function() {
        $('#vpFilterParts').val(null).selectpicker('refresh');
        loadList();
    });
    $('#vpClearStatus').on('click', function() {
        setStatus('vpFilterStatus', 'all');
        loadList();
    });
    $('#vpClearSearch').on('click', function() {
        $('#vpFilterSearch').val('');
        loadList();
    });
    $('#vpClearDates').on('click', function() {
        $('#vpFilterDateFrom').val('');
        $('#vpFilterDateTo').val('');
        loadList();
    });

    // First paint — capture the full option lists BEFORE any cascade can
    // mutate the DOM, then load.
    snapshotFullLists();
    loadList();
});