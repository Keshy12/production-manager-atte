/**
 * vendor-parts-view.js
 *
 * Admin view for /admin/purchase/vendor-parts. The page is filter-driven:
 * a collapsible filter card, an AJAX-rendered table (vp-list.php) and
 * pagination. There is no add/edit form on this page.
 *
 * Adding and editing happen on a dedicated page — see
 * /admin/purchase/vendor-parts/edit (rendered by
 * edit/vendor-part-edit-view.php). Each row's pencil button is a plain
 * anchor that navigates there.
 */

$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/VendorParts/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/vendor-parts/edit?id=";

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
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

    // Count filters that differ from the page defaults (status "active",
    // everything else empty). Shown as a pill in the collapsed card header
    // so a hidden filter never silently narrows the list.
    function updateFilterCount() {
        const f = gatherFilters();
        let n = 0;
        if (f.vendorIds.length)   n++;
        if (f.producerIds.length) n++;
        if (f.partIds.length)     n++;
        if (f.status !== 'active') n++;
        if (f.search.trim() !== '') n++;
        if (f.dateFrom) n++;
        if (f.dateTo)   n++;
        $('#vpFilterCount').text(n).prop('hidden', n === 0);
    }

    function renderRow(vp) {
        const isActive = vp.isActive === true || vp.isActive === 1 || vp.isActive === '1';
        // Inactive rows are conveyed by the table-secondary tint, the dim
        // text CSS rule and the muted status badge.
        const rowClass = isActive ? '' : 'vp-row--inactive table-secondary';

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

        // Comment: free text, mostly empty — a small icon next to the name
        // with the full text in the tooltip instead of its own column.
        const rawCmt = (vp.comment == null ? '' : String(vp.comment)).trim();
        const commentIcon = rawCmt
            ? '<i class="bi bi-journal-text vp-comment-icon" title="' + esc(rawCmt) + '" aria-label="Komentarz: ' + esc(rawCmt) + '"></i>'
            : '';

        // Dostawca cell — vendor name on the main line, producer name as a
        // muted sub-line (omitted when missing).
        let dostawcaCell = '<span class="vp-name">' + esc(vp.vendorName || '—') + '</span>';
        if (vp.producerName) {
            dostawcaCell += '<span class="vp-sub">' + esc(vp.producerName) + '</span>';
        }

        const statusBadge = isActive
            ? '<span class="badge badge-success">Aktywny</span>'
            : '<span class="badge vp-badge-off">Nieaktywny</span>';

        const editTitle = 'Edytuj artykuł #' + vp.id;

        return '<tr class="' + rowClass + '" data-id="' + vp.id + '">' +
            '<td class="vp-col-id text-center">' + vp.id + '</td>' +
            '<td><div class="d-flex align-items-start"><div style="min-width:0;">' + artykulCell + '</div>' + commentIcon + '</div></td>' +
            '<td>' + dostawcaCell + '</td>' +
            '<td>' + renderPacksHtml(vp.packQuantities, vp.unitName) + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm btn-warning" href="' + editBase + vp.id + '"' +
                    ' title="' + esc(editTitle) + '" aria-label="' + esc(editTitle) + '">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    // Pusty stan zastępuje tabelę; błąd ładowania nie oferuje „Dodaj”.
    function showEmpty(message, withCta) {
        $('#vpEmptyText').text(message);
        $('#vpEmptyCta').prop('hidden', !withCta);
        $('#vpTableWrap').prop('hidden', true);
        $('#vpEmpty').prop('hidden', false);
    }

    function hideEmpty() {
        $('#vpEmpty').prop('hidden', true);
        $('#vpTableWrap').prop('hidden', false);
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
        updateFilterCount();

        postAjax('vp-list.php', filters)
            .done(function(r) {
                if (!r.success) {
                    setAlert(r.error || 'Błąd ładowania listy', 'danger');
                    showEmpty('Nie udało się załadować danych.', false);
                    totalCount = 0;
                    $('#vpCountBadge').text('0');
                    renderPagination();
                    return;
                }
                totalCount = r.total || 0;
                itemsPerPage = r.itemsPerPage || itemsPerPage;
                $('#vpCountBadge').text(totalCount);

                if (totalCount === 0) {
                    showEmpty('Brak artykułów spełniających kryteria — zmień filtry albo kliknij Dodaj artykuł.', true);
                } else {
                    hideEmpty();
                    const html = (r.rows || []).map(renderRow).join('');
                    $('#vpTableBody').html(html);
                }
                renderPagination();
            })
            .fail(function() {
                setAlert('Błąd komunikacji z serwerem', 'danger');
                showEmpty('Nie udało się załadować danych.', false);
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

    // Single "Wyczyść filtry" button in the toolbar: resets every filter
    // and reloads once (separate per-section buttons would race against
    // the isLoading guard in loadList()).
    $('#vpClearAll').on('click', function() {
        // Reset BOTH vendor/producer pickers back to the full list cached
        // at page load AND drop the current pick. Clearing .val() before
        // the rebuild keeps rebuildSelectKeepValid from re-pinning a value.
        $('#vpFilterVendors').val(null);
        $('#vpFilterProducers').val(null);
        rebuildSelectKeepValid('#vpFilterVendors',   vpFullVendors);
        rebuildSelectKeepValid('#vpFilterProducers', vpFullProducers);
        $('#vpFilterParts').val(null).selectpicker('refresh');
        setStatus('vpFilterStatus', 'all');
        $('#vpFilterSearch').val('');
        $('#vpFilterDateFrom').val('');
        $('#vpFilterDateTo').val('');
        loadList();
    });

    // The pickers are first measured while their card is collapsed
    // (display:none), so bootstrap-select can't size its menu correctly.
    // Refresh them once the card is actually visible.
    $('#vpFilterCollapse').on('shown.bs.collapse', function() {
        $(this).find('.selectpicker').selectpicker('refresh');
    });

    // First paint — capture the full option lists BEFORE any cascade can
    // mutate the DOM, then load.
    snapshotFullLists();
    loadList();
});
