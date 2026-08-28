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

    function renderRow(v) {
        const isActive = v.isActive === true || v.isActive === 1 || v.isActive === '1';
        const rowClass = isActive ? '' : 'vr-row--inactive table-secondary';

        const nameHtml = esc(v.name || '—');

        // Adres cell — muted em-dash when empty so the column doesn't
        // collapse on rows that only have a name.
        const addressHtml = (v.address && v.address.trim() !== '')
            ? '<small class="text-muted">' + esc(v.address) + '</small>'
            : '<span class="text-muted">—</span>';

        // Komentarz cell — same truncate-at-200 + title tooltip as
        // producer / vendor-parts listings.
        const COMMENT_TRUNCATE_AT = 200;
        const rawCmt = (v.comment == null ? '' : String(v.comment)).trim();
        let komentarzCell;
        if (!rawCmt) {
            komentarzCell = '<span class="text-muted">—</span>';
        } else if (rawCmt.length > COMMENT_TRUNCATE_AT) {
            const visible = esc(rawCmt.slice(0, COMMENT_TRUNCATE_AT).trimEnd()) + '…';
            komentarzCell = '<small class="text-muted" title="' + esc(rawCmt) + '"><i class="bi bi-journal-text"></i> ' + visible + '</small>';
        } else {
            komentarzCell = '<small class="text-muted"><i class="bi bi-journal-text"></i> ' + esc(rawCmt) + '</small>';
        }

        // Lead time — int + "dni" badge, or muted em-dash when null.
        const lt = parseInt(v.leadTimeDays, 10);
        const leadTimeCell = (isNaN(lt) || lt === null)
            ? '<span class="text-muted">—</span>'
            : '<span class="badge badge-secondary">' + lt + ' dni</span>';

        // Supplier / vendor-part counts — 0 reads as '—' so the
        // column doesn't look like "0 used".
        const spCount = parseInt(v.supplierCount, 10);
        const spCell  = (isNaN(spCount) || spCount <= 0)
            ? '<span class="text-muted">—</span>'
            : '<span class="badge badge-info">' + spCount + '</span>';

        const vpCount = parseInt(v.vendorPartCount, 10);
        const vpCell  = (isNaN(vpCount) || vpCount <= 0)
            ? '<span class="text-muted">—</span>'
            : '<span class="badge badge-info">' + vpCount + '</span>';

        return '<tr class="' + rowClass + '" data-id="' + v.id + '">' +
            '<td class="vr-col-id text-center">' + v.id + '</td>' +
            '<td>' + nameHtml + '</td>' +
            '<td>' + addressHtml + '</td>' +
            '<td>' + komentarzCell + '</td>' +
            '<td class="text-center">' + leadTimeCell + '</td>' +
            '<td class="text-center">' + spCell + '</td>' +
            '<td class="text-center">' + vpCell + '</td>' +
            '<td>' +
                '<a class="btn btn-sm btn-warning" href="' + editBase + v.id + '">' +
                    '<i class="bi bi-pencil"></i> Edytuj' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    function renderEmptyRow(message) {
        return '<tr><td colspan="8" class="text-center text-muted py-4">' +
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

        $('#vrPaginationTop, #vrPaginationBottom').html(html);

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

        postAjax('vendor-list.php', filters)
            .done(function(r) {
                if (!r.success) {
                    setAlert(r.error || 'Błąd ładowania listy', 'danger');
                    $('#vrTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
                    totalCount = 0;
                    renderPagination();
                    return;
                }
                totalCount = r.total || 0;
                itemsPerPage = r.itemsPerPage || itemsPerPage;

                if (totalCount === 0) {
                    $('#vrTableBody').html(renderEmptyRow('Brak dostawców spełniających kryteria'));
                } else {
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

    // Per-section "Wyczyść" buttons.
    $('#vrClearStatus').on('click', function() {
        setStatus('vrFilterStatus', 'all');
        loadList();
    });
    $('#vrClearSearch').on('click', function() {
        $('#vrFilterSearch').val('');
        loadList();
    });
    $('#vrClearHasArticles').on('click', function() {
        setStatus('vrFilterHasArticles', 'all');
        loadList();
    });
    $('#vrClearHasSuppliers').on('click', function() {
        setStatus('vrFilterHasSuppliers', 'all');
        loadList();
    });
    $('#vrClearDates').on('click', function() {
        $('#vrFilterDateFrom').val('');
        $('#vrFilterDateTo').val('');
        loadList();
    });

    // First paint.
    loadList();
});