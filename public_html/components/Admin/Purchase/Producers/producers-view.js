/**
 * producers-view.js
 *
 * Admin view for /admin/purchase/producers. The page is filter-driven:
 * no inline add form, just a collapsible "Filtry" card (collapsed by
 * default, with a pill counting non-default filters), an AJAX-rendered
 * table, and pagination. Adding a new producer is done
 * via a dedicated edit page at /admin/purchase/producers/edit (the
 * "Dodaj producenta" button in the page header navigates there).
 *
 * The edit flow is a dedicated page — see /admin/purchase/producers/edit
 * (wired in index.php and rendered by edit/producer-edit-view.php). Each
 * row's "Edytuj" button is a plain anchor that navigates there. The
 * legacy modal + producer-get.php / producer-add.php /
 * producer-update.php / producer-toggle-active.php endpoints have all
 * been removed.
 *
 * Pattern mirrors vendor-parts-view.js — same shape, adapted for the
 * narrower producer schema (id, name, comment, isActive, vendorPartCount).
 */
$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Producers/";
    const editBase = window.location.origin + "/atte_ms_new/admin/purchase/producers/edit?id=";

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
    // itemsPerPage comes from the server response (producer-list.php) so the
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

    // Map hasArticles UI value ('all' | 'used' | 'unused') to the
    // backend's boolean-ish wire format (null | true | false). 'all' is
    // null so the repo's buildSearchWhere() treats it as no-op.
    function getHasArticles() {
        const raw = $('input[name="prFilterHasArticles"]:checked').val() || 'all';
        if (raw === 'used')   return true;
        if (raw === 'unused') return false;
        return null;
    }

    // Surface the ?updated=N / ?created=1 success flashes sent back
    // from the edit page (producer-edit-save.php redirects to the
    // listing with that param after a successful save or create).
    // Show them once, then strip from URL via history.replaceState so
    // a refresh doesn't re-show the toast.
    const params = new URLSearchParams(window.location.search);
    if (params.has('updated')) {
        const updatedId = params.get('updated');
        setAlert('Zapisano zmiany (producent #' + updatedId + ').', 'success');
        params.delete('updated');
    }
    if (params.has('created')) {
        const createdId = params.get('created');
        setAlert('Producent #' + createdId + ' został dodany.', 'success');
        params.delete('created');
    }
    if (params.toString() !== window.location.search.replace(/^\?/, '')) {
        const newQuery = params.toString();
        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
        window.history.replaceState({}, '', newUrl);
    }

    function gatherFilters() {
        return {
            status:      getStatus('prFilterStatus'),
            search:      $('#prFilterSearch').val() || '',
            hasArticles: getHasArticles(),
            page:        currentPage,
        };
    }

    function renderRow(p) {
        const isActive = p.isActive === true || p.isActive === 1 || p.isActive === '1';
        // Inactive rows are conveyed by the table-secondary tint + the
        // dim text CSS rule. Active rows are plain (white background).
        const rowClass = isActive ? '' : 'pr-row--inactive table-secondary';

        const nameRaw = p.name || '—';

        // Komentarz jako dopisek pod nazwą (bez własnej kolumny); gdy go
        // nie ma, komórka zawiera samą nazwę. Skracanie robi CSS, pełny
        // tekst w title.
        const rawCmt = (p.comment == null ? '' : String(p.comment)).trim();
        const noteHtml = rawCmt
            ? '<span class="pr-note" title="' + esc(rawCmt) + '">' + esc(rawCmt) + '</span>'
            : '';

        // Liczba artykułów — 0 pokazujemy jako myślnik, żeby kolumna
        // nie wyglądała jak ostrzeżenie; dodatnie wartości jako liczba.
        const vpCount = parseInt(p.vendorPartCount, 10);
        const vpCell = (isNaN(vpCount) || vpCount <= 0)
            ? '<span class="text-muted">—</span>'
            : '<span class="badge badge-info">' + vpCount + '</span>';

        const statusBadge = isActive
            ? '<span class="badge badge-success">Aktywny</span>'
            : '<span class="badge pr-badge-off">Nieaktywny</span>';

        return '<tr class="' + rowClass + '" data-id="' + p.id + '">' +
            '<td class="pr-col-id text-center">' + p.id + '</td>' +
            '<td><span class="pr-name">' + esc(nameRaw) + '</span>' + noteHtml + '</td>' +
            '<td class="text-center pr-col-count">' + vpCell + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center">' +
                '<a class="btn btn-sm btn-warning" href="' + editBase + p.id + '"' +
                    ' title="Edytuj producenta „' + esc(nameRaw) + '”"' +
                    ' aria-label="Edytuj producenta">' +
                    '<i class="bi bi-pencil"></i>' +
                '</a>' +
            '</td>' +
        '</tr>';
    }

    function renderEmptyRow(message) {
        return '<tr><td colspan="5" class="text-center text-muted py-4">' +
            esc(message) + '</td></tr>';
    }

    // Liczba filtrów różnych od stanu domyślnego (status „Aktywne”,
    // pusta fraza, wszystkie artykuły). Domyślny widok nie liczy się,
    // bo operator nic w nim nie zmienił.
    function countNonDefaultFilters() {
        let n = 0;
        if (getStatus('prFilterStatus') !== 'active') { n++; }
        if (($('#prFilterSearch').val() || '').trim() !== '') { n++; }
        if (getHasArticles() !== null) { n++; }
        return n;
    }

    // Karta filtrów jest zwinięta, więc licznik w jej nagłówku jest
    // jedynym sygnałem, że lista jest zawężona.
    function updateFilterPill() {
        const n = countNonDefaultFilters();
        $('#prFilterPill').text(n).prop('hidden', n === 0);
    }

    // Pusty stan zastępuje tabelę. Gdy filtry coś zawężają, główną
    // akcją jest ich wyczyszczenie; w widoku domyślnym — dodanie.
    function showEmptyState() {
        const filtered = countNonDefaultFilters() > 0;
        const html = filtered
            ? '<i class="bi bi-search"></i>' +
              '<p class="mb-3 text-muted">Żaden producent nie pasuje do wybranych filtrów.</p>' +
              '<button type="button" id="prEmptyClear" class="btn btn-outline-secondary btn-sm">' +
                  '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'
            : '<i class="bi bi-building"></i>' +
              '<p class="mb-3 text-muted">Brak aktywnych producentów — kliknij <strong>Dodaj producenta</strong>, aby dodać pierwszego.</p>' +
              '<a href="' + esc(window.PR_ADD_URL || '#') + '" class="btn btn-primary btn-sm">' +
                  '<i class="bi bi-plus-circle"></i> Dodaj producenta</a>';
        $('#prEmpty').html(html).prop('hidden', false);
        $('#prTableWrap').prop('hidden', true);
        $('#prEmptyClear').on('click', clearAllFilters);
    }

    function hideEmptyState() {
        $('#prEmpty').prop('hidden', true).empty();
        $('#prTableWrap').prop('hidden', false);
    }

    function updateCounts() {
        $('#prCountBadge').text(totalCount).prop('hidden', false);
        $('#prSummaryCount').text(totalCount);
    }

    function clearAllFilters() {
        // Powrót do widoku domyślnego (Aktywne), tego samego, który
        // licznik w nagłówku karty uznaje za „brak filtrów”.
        setStatus('prFilterStatus', 'active');
        setStatus('prFilterHasArticles', 'all');
        $('#prFilterSearch').val('');
        loadList();
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
                    '<button class="btn btn-outline-primary pr-page-btn" data-action="first" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-double-left"></i></button>' +
                    '<button class="btn btn-outline-primary pr-page-btn" data-action="prev" ' +
                        (hasPrev ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-left"></i></button>' +
                    '<div class="btn-group">' +
                        '<button type="button" class="btn btn-primary dropdown-toggle" ' +
                            'data-toggle="dropdown">' + currentPage + '</button>' +
                        '<div class="dropdown-menu pr-page-dropdown" ' +
                            'style="max-height: 300px; overflow-y: auto;"></div>' +
                    '</div>' +
                    '<button class="btn btn-outline-primary pr-page-btn" data-action="next" ' +
                        (hasNext ? '' : 'disabled') + '>' +
                        '<i class="bi bi-chevron-right"></i></button>' +
                '</div>' +
            '</div>';

        $('#prPaginationTop, #prPaginationBottom').html(html);

        // Build page dropdown items
        let pageItems = '';
        for (let i = 1; i <= pages; i++) {
            pageItems += '<a class="dropdown-item pr-page-jump' +
                (i === currentPage ? ' active' : '') +
                '" href="#" data-page="' + i + '">' + i + '</a>';
        }
        $('.pr-page-dropdown').html(pageItems);

        $('.pr-page-btn').off('click').on('click', function() {
            const act = $(this).data('action');
            if (act === 'first')         { currentPage = 1; }
            else if (act === 'prev' && currentPage > 1)   { currentPage--; }
            else if (act === 'next' && currentPage < pages) { currentPage++; }
            else { return; }
            loadList(true);
        });
        $('.pr-page-jump').off('click').on('click', function(e) {
            e.preventDefault();
            currentPage = parseInt($(this).data('page'), 10) || 1;
            loadList(true);
        });
    }

    function loadList(skipPageReset) {
        updateFilterPill();
        if (isLoading) return;
        if (!skipPageReset) { currentPage = 1; }

        isLoading = true;
        $('#prSpinner').prop('hidden', false);
        setAlert('', null); // clear stale alerts on every reload

        const filters = gatherFilters();

        postAjax('producer-list.php', filters)
            .done(function(r) {
                if (!r.success) {
                    setAlert(r.error || 'Błąd ładowania listy', 'danger');
                    hideEmptyState();
                    $('#prTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
                    totalCount = 0;
                    updateCounts();
                    renderPagination();
                    return;
                }
                totalCount = r.total || 0;
                itemsPerPage = r.itemsPerPage || itemsPerPage;
                updateCounts();

                if (totalCount === 0) {
                    $('#prTableBody').empty();
                    showEmptyState();
                    $('#prPaginationTop, #prPaginationBottom').empty();
                } else {
                    hideEmptyState();
                    const html = (r.rows || []).map(renderRow).join('');
                    $('#prTableBody').html(html);
                    renderPagination();
                }
            })
            .fail(function() {
                setAlert('Błąd komunikacji z serwerem', 'danger');
                $('#prTableBody').html(renderEmptyRow('Nie udało się załadować danych.'));
            })
            .always(function() {
                isLoading = false;
                $('#prSpinner').prop('hidden', true);
            });
    }

    // ============================================================
    // Filter card / quick controls wiring
    // ============================================================

    // ---------- Filter change wiring ----------
    $('#prFilterSearch').on('input', function() {
        loadList();
    });

    // Status segmented buttons trigger reload.
    function bindStatusGroup(groupName) {
        $('input[name="' + groupName + '"]').on('change', function() {
            loadList();
        });
    }
    bindStatusGroup('prFilterStatus');
    bindStatusGroup('prFilterHasArticles');

    // Jeden przycisk „Wyczyść filtry” zamiast trzech osobnych.
    $('#prClearFilters').on('click', clearAllFilters);

    // First paint.
    loadList();
});