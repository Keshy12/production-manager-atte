/**
 * vendors-view.js
 *
 * Admin view for /admin/purchase/vendors. After the redesign this
 * page is filter-driven: no inline add form, just a filter card, an
 * AJAX-rendered table, and pagination. Adding a new vendor is done
 * via a dedicated edit page at /admin/purchase/vendors/edit (the
 * "Dodaj dostawcę" button in the page header navigates there).
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
 * Pattern mirrors vendor-parts-view.js / producers-view.js — same
 * shape, adapted for the wider vendor schema (id, name, address,
 * additionalData, leadTimeDays, comment, isActive, supplierCount,
 * vendorPartCount, createdAt).
 */
$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Vendors/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/vendors/edit?id=";

    // ---------- Helpers ----------
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // ============================================================
    // Filter / pagination / table-render logic
    // ============================================================
    // itemsPerPage comes from the server response (vendor-list.php) so
    // the client and backend share one source of truth.
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

    // Map hasArticles / hasSuppliers UI value ('all' | 'used' | 'unused')
    // to the backend's boolean-ish wire format (null | true | false).
    // 'all' is null so the repo's buildSearchWhere() treats it as no-op.
    function getTriBool(name) {
        const raw = $('input[name="' + name + '"]:checked').val() || 'all';
        if (raw === 'used')   return true;
        if (raw === 'unused') return false;
        return null;
    }

    // Surface the ?updated=N / ?created=1 success flashes sent back
    // from the edit page. Show them once, then strip from URL via
    // history.replaceState so a refresh doesn't re-show the toast.
    const params = new URLSearchParams(window.location.search);
    if (params.has('updated')) {
        const updatedId = params.get('updated');
        setAlert('Zapisano zmiany (dostawca #' + updatedId + ').', 'success');
        params.delete('updated');
    }
    if (params.has('created')) {
        const createdId = params.get('created');
        setAlert('Dostawca #' + createdId + ' został dodany.', 'success');
        params.delete('created');
    }
    if (params.toString() !== window.location.search.replace(/^\?/, '')) {
        const newQuery = params.toString();
        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
        window.history.replaceState({}, '', newUrl);
    }

    function gatherFilters() {
        return {
            status:       getStatus('vrFilterStatus'),
            search:       $('#vrFilterSearch').val() || '',
            hasArticles:  getTriBool('vrFilterHasArticles'),
            hasSuppliers: getTriBool('vrFilterHasSuppliers'),
            dateFrom:     $('#vrFilterDateFrom').val() || '',
            dateTo:       $('#vrFilterDateTo').val()   || '',
            page:         currentPage,
        };
    }

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
        const rowClass = isActive ? '' : 'vr-row--inactive table-secondary';

        // Name + muted second line (address). Comment is reduced to an
        // icon whose title holds the full text.
        const rawAddr = (v.address == null ? '' : String(v.address)).trim();
        const rawCmt  = (v.comment == null ? '' : String(v.comment)).trim();
        const noteIcon = rawCmt
            ? '<i class="bi bi-journal-text vr-note" title="' + esc(rawCmt) + '"></i>'
            : '';
        const subHtml = (rawAddr || rawCmt)
            ? '<span class="vr-sub"' + (rawAddr ? ' title="' + esc(rawAddr) + '"' : '') + '>' +
                esc(rawAddr) + noteIcon + '</span>'
            : '';

        const lt = parseInt(v.leadTimeDays, 10);
        const leadTimeCell = isNaN(lt) ? dash() : lt + ' dni';

        const linksCell =
            linkCount('bi-person', v.supplierCount, 'Osoby kontaktowe') +
            linkCount('bi-box-seam', v.vendorPartCount, 'Artykuły u dostawcy');

        const statusBadge = isActive
            ? '<span class="badge badge-success">Aktywny</span>'
            : '<span class="badge vr-badge-off">Nieaktywny</span>';

        const label = esc(v.name || '');

        return '<tr class="' + rowClass + '" data-id="' + v.id + '">' +
            '<td class="vr-col-id text-center">' + v.id + '</td>' +
            '<td><span class="vr-name">' + esc(v.name || '—') + '</span>' + subHtml + '</td>' +
            '<td class="text-center vr-col-num">' + leadTimeCell + '</td>' +
            '<td class="text-center vr-col-num vr-links">' + linksCell + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm btn-warning" href="' + editBase + v.id + '"' +
                   ' title="Edytuj dostawcę „' + label + '”" aria-label="Edytuj dostawcę">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    function renderEmptyRow(message) {
        return '<tr><td colspan="6" class="text-center text-muted py-4">' +
            esc(message) + '</td></tr>';
    }

    // Counts filters that differ from the page defaults (status = active,
    // everything else off). Date range counts as one filter.
    function countActiveFilters() {
        const f = gatherFilters();
        let n = 0;
        if (f.status !== 'active')        { n++; }
        if (f.search.trim() !== '')       { n++; }
        if (f.hasArticles !== null)       { n++; }
        if (f.hasSuppliers !== null)      { n++; }
        if (f.dateFrom !== '' || f.dateTo !== '') { n++; }
        return n;
    }

    function updateFilterPill() {
        const n = countActiveFilters();
        $('#vrFilterPill').text(n).prop('hidden', n === 0);
    }

    // Empty state replaces the table; with extra filters on it offers a
    // reset instead of "add", since the catalogue may not be empty.
    function showEmpty(show) {
        $('#vrEmpty').prop('hidden', !show);
        $('#vrTableWrap').toggle(!show);
        if (show) {
            const filtered = countActiveFilters() > 0;
            $('#vrEmptyText').text(filtered
                ? 'Żaden dostawca nie spełnia wybranych kryteriów. Zmień lub wyczyść filtry.'
                : 'Brak aktywnych dostawców — dodaj pierwszego lub zmień status w filtrach.');
            $('#vrEmptyAdd').prop('hidden', filtered);
            $('#vrEmptyClear').prop('hidden', !filtered);
        }
    }

    // Back to the page defaults (same state as on first load).
    function resetFilters() {
        setStatus('vrFilterStatus', 'active');
        setStatus('vrFilterHasArticles', 'all');
        setStatus('vrFilterHasSuppliers', 'all');
        $('#vrFilterSearch').val('');
        $('#vrFilterDateFrom').val('');
        $('#vrFilterDateTo').val('');
        loadList();
    }
    function renderPagination() {
        const pages = Math.max(1, Math.ceil(totalCount / itemsPerPage));
        const start = totalCount === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1;
        const end   = Math.min(currentPage * itemsPerPage, totalCount);
        const hasPrev = currentPage > 1;
        const hasNext = currentPage < pages;

        // Range goes to the summary line above the table (right-aligned,
        // like the counters on the email-drafts list).
        $('#vrSummary').html(totalCount === 0 ? '' :
            'Wyświetlanie <strong>' + start + '–' + end +
            '</strong> z <strong>' + totalCount + '</strong>');
        if (totalCount > 0) {
            $('#vrCountBadge').text(totalCount).prop('hidden', false);
        } else {
            $('#vrCountBadge').prop('hidden', true);
        }

        // Single page: no pager needed.
        if (pages <= 1) {
            $('#vrPaginationBottom').empty();
            return;
        }

        const html =
            '<div class="d-flex justify-content-center my-2">' +
                '<div class="btn-group btn-group-sm" role="group">' +
                    '<button class="btn btn-outline-primary vr-page-btn" data-action="first" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-double-left"></i></button>' +
                    '<button class="btn btn-outline-primary vr-page-btn" data-action="prev" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-left"></i></button>' +
                    '<div class="btn-group">' +
                        '<button type="button" class="btn btn-primary dropdown-toggle" ' +
                            'data-toggle="dropdown">' + currentPage + '</button>' +
                        '<div class="dropdown-menu vr-page-dropdown" ' +
                            'style="max-height: 300px; overflow-y: auto;"></div>' +
                    '</div>' +
                    '<button class="btn btn-outline-primary vr-page-btn" data-action="next" ' +
                        (hasNext ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-right"></i></button>' +
                '</div>' +
            '</div>';

        $('#vrPaginationBottom').html(html);

        // Build page dropdown items
        let pageItems = '';
        for (let i = 1; i <= pages; i++) {
            pageItems += '<a class="dropdown-item vr-page-jump' +
                (i === currentPage ? ' active' : '') +
                '" href="#" data-page="' + i + '">' + i + '</a>';
        }
        $('.vr-page-dropdown').html(pageItems);

        $('.vr-page-btn').off('click').on('click', function() {
            const act = $(this).data('action');
            if (act === 'first')         { currentPage = 1; }
            else if (act === 'prev' && currentPage > 1)   { currentPage--; }
            else if (act === 'next' && currentPage < pages) { currentPage++; }
            else { return; }
            loadList(true);
        });
        $('.vr-page-jump').off('click').on('click', function(e) {
            e.preventDefault();
            currentPage = parseInt($(this).data('page'), 10) || 1;
            loadList(true);
        });
    }

    function loadList(skipPageReset) {
        if (isLoading) return;
        if (!skipPageReset) { currentPage = 1; }

        isLoading = true;
        $('#vrSpinner').prop('hidden', false);
        setAlert('', null); // clear stale alerts on every reload

        const filters = gatherFilters();
        updateFilterPill();

        postAjax('vendor-list.php', filters)
            .done(function(r) {
                if (!r.success) {
                    setAlert(r.error || 'Błąd ładowania listy', 'danger');
                    showEmpty(false);
                    $('#vrTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
                    totalCount = 0;
                    renderPagination();
                    return;
                }
                totalCount = r.total || 0;
                itemsPerPage = r.itemsPerPage || itemsPerPage;

                if (totalCount === 0) {
                    $('#vrTableBody').empty();
                    showEmpty(true);
                } else {
                    showEmpty(false);
                    const html = (r.rows || []).map(renderRow).join('');
                    $('#vrTableBody').html(html);
                }
                renderPagination();
            })
            .fail(function() {
                setAlert('Błąd komunikacji z serwerem', 'danger');
                $('#vrTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
            })
            .always(function() {
                isLoading = false;
                $('#vrSpinner').prop('hidden', true);
            });
    }

    // ============================================================
    // Filter card / quick controls wiring
    // ============================================================

    // ---------- Filter change wiring ----------
    $('#vrFilterSearch').on('input', function() {
        loadList();
    });
    $('#vrFilterDateFrom, #vrFilterDateTo').on('change', function() { loadList(); });

    // Status segmented buttons trigger reload.
    function bindStatusGroup(groupName) {
        $('input[name="' + groupName + '"]').on('change', function() {
            loadList();
        });
    }
    bindStatusGroup('vrFilterStatus');
    bindStatusGroup('vrFilterHasArticles');
    bindStatusGroup('vrFilterHasSuppliers');

    // One reset button replaces the five per-section "Wyczyść" buttons.
    $('#vrClearAll, #vrEmptyClear').on('click', resetFilters);

    // First paint.
    loadList();
});