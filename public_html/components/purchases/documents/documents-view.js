/**
 * documents-view.js
 * Combined RFQ + PO table at /purchase/documents. Mirrors the AJAX lifecycle,
 * filter card markup, and pagination widget from /purchase/cart's neighbours
 * (cart-view.js, archive-view.js) so the operator's mental model transfers.
 *
 * Filter shape (matches documents-table.php exactly):
 *   type           : 'both' | 'rfq' | 'po'                 single
 *   vendor_ids[]   : int[]                                 multi
 *   states[]       : string[]                              multi
 *   number         : string                                partial LIKE
 *   date_from /    : 'YYYY-MM-DD' (default = today - 90d on first paint)
 *   date_to
 *   page           : int
 *   mode           : 'data' | 'count'                      server-driven
 *
 * Row action button — single endpoint:
 *   editable === true  → "Edytuj" pencil → /admin/purchase/documents/edit?id=N&type=…
 *   editable === false → "Podgląd" eye    → /admin/purchase/documents/edit?id=N&type=…
 * The /edit page already renders read-only when the doc's state isn't in
 * PurchaseActionHandler::allowedEditStates() — no separate /view route needed.
 */

let currentPage = 1;
let totalCount = null;
let hasNextPage = false;
let itemsPerPage = 20;
let isLoading = false;
let currentSnapshotTs = null;

// State badge maps, matching documents-edit.php so the row badge + the
// detail page badge read identically. Single source of truth lives in PHP;
// JS keeps a parallel map so we don't roundtrip just for styling.
const STATE_BADGE_CLASS = {
    rfq: {
        draft:     'badge-secondary',
        sent:      'badge-primary',
        responded: 'badge-info',
        cancelled: 'badge-danger',
        converted: 'badge-success',
    },
    po: {
        draft:              'badge-secondary',
        sent:               'badge-primary',
        confirmed:          'badge-info',
        partially_received: 'badge-warning',
        received:           'badge-success',
        cancelled:          'badge-danger',
    },
};
const STATE_BADGE_LABEL = {
    rfq: {
        draft:     'szkic',
        sent:      'wysłane',
        responded: 'odpowiedź',
        cancelled: 'anulowane',
        converted: 'przekonwertowane',
    },
    po: {
        draft:              'szkic',
        sent:               'wysłane',
        confirmed:          'potwierdzone',
        partially_received: 'częściowo odebrane',
        received:           'odebrane',
        cancelled:          'anulowane',
    },
};

$(document).ready(function () {
    $('.selectpicker').selectpicker();

    // First-paint: default the 'od' date to today − 90d so the page lands
    // on something useful. 'do' stays empty. User can clear both to see
    // everything older.
    const ninetyDaysAgo = new Date();
    ninetyDaysAgo.setDate(ninetyDaysAgo.getDate() - 90);
    $('#dateFrom').val(ninetyDaysAgo.toISOString().split('T')[0]);

    attachEventHandlers();
    loadDocuments();
});

function attachEventHandlers() {
    // Any filter change → reset to page 1 + reload.
    $('#type, #vendor, #states, #number, #dateFrom, #dateTo').on('change keyup', function () {
        // `keyup` covers the free-text #number field so each keystroke
        // re-loads; debounce is omitted for v1 (20 results/page → cheap).
        resetToFirstPage();
        loadDocuments();
    });

    $('#refreshDocs').on('click', function () {
        resetToFirstPage();
        loadDocuments();
    });

    // Per-row clear buttons (mirror /archive).
    $('#clearType').on('click', function () {
        $('#type').val('both').selectpicker('refresh');
        resetToFirstPage();
        loadDocuments();
    });
    $('#clearVendor').on('click', function () {
        $('#vendor').val([]).selectpicker('refresh');
        resetToFirstPage();
        loadDocuments();
    });
    $('#clearStates').on('click', function () {
        $('#states').val([]).selectpicker('refresh');
        resetToFirstPage();
        loadDocuments();
    });
    $('#clearNumber').on('click', function () {
        $('#number').val('');
        resetToFirstPage();
        loadDocuments();
    });
    $('#clearDates').on('click', function () {
        $('#dateFrom, #dateTo').val('');
        resetToFirstPage();
        loadDocuments();
    });
}

function resetToFirstPage() {
    currentPage = 1;
    totalCount = null;
    currentSnapshotTs = null;
}

function loadDocuments() {
    if (isLoading) return;
    isLoading = true;
    $('#docsSpinner').show();

    const filters = {
        type:       $('#type').val() || 'both',
        vendor_ids: $('#vendor').val() || [],
        states:     $('#states').val() || [],
        number:     $('#number').val() || '',
        date_from:  $('#dateFrom').val() || '',
        date_to:    $('#dateTo').val() || '',
        page:       currentPage,
        items_per_page: itemsPerPage,
        mode:       'data',
        snapshot_ts: currentSnapshotTs,
    };

    const path = (typeof COMPONENTS_PATH !== 'undefined')
        ? COMPONENTS_PATH
        : '/atte_ms_new/public_html/components';

    $.ajax({
        type: 'POST',
        url: path + '/purchases/documents/documents-table.php',
        data: filters,
        dataType: 'json',
    })
    .done(function (res) {
        currentSnapshotTs = res.snapshot_ts || currentSnapshotTs;
        renderTable(res.docs || []);
        if (totalCount === null) {
            loadTotalCount(filters);
        } else {
            hasNextPage = totalCount > currentPage * itemsPerPage;
            renderPagination();
        }
        isLoading = false;
        $('#docsSpinner').hide();
    })
    .fail(function (xhr, status, error) {
        console.error('documents-table AJAX error:', error);
        isLoading = false;
        $('#docsSpinner').hide();
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
            || 'Błąd ładowania dokumentów.';
        if (typeof setAlert === 'function') {
            setAlert(msg, 'danger');
        } else {
            alert(msg);
        }
    });
}

function loadTotalCount(filters) {
    const path = (typeof COMPONENTS_PATH !== 'undefined')
        ? COMPONENTS_PATH
        : '/atte_ms_new/public_html/components';
    $.ajax({
        type: 'POST',
        url: path + '/purchases/documents/documents-table.php',
        data: { ...filters, mode: 'count' },
        dataType: 'json',
    })
    .done(function (res) {
        totalCount = (res && typeof res.totalCount === 'number') ? res.totalCount : 0;
        hasNextPage = totalCount > currentPage * itemsPerPage;
        renderPagination();
    });
}

function escapeHtml(s) {
    return (s == null ? '' : String(s))
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatPrice(n) {
    const num = parseFloat(n);
    if (!isFinite(num)) return '0';
    // Group thousands, no enforced minimum fraction digits (so we can
    // strip trailing zeros next without leaving '550,000.00' stuck).
    let s = num.toLocaleString('en-US', {
        useGrouping: true,
        maximumFractionDigits: 10,
    });
    // Drop trailing zeros from the fractional part, then a dangling '.'
    // if the fraction became empty. So 550000.0000 → '550000', '1 650 075.5000'
    // → '1 650 075.5'.
    s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    // Polish convention: thousand separator is a space, not a comma.
    return s.replace(/,/g, ' ');
}

// Render the value column from the per-currency breakdown the server
// returns. Falls back to a muted dash when the doc has no priced items.
function renderValue(breakdown) {
    if (!Array.isArray(breakdown) || breakdown.length === 0) {
        return '<span class="text-muted">—</span>';
    }
    const lines = breakdown.map(function (b) {
        return '<div><small class="text-muted">'
             + formatPrice(b.total) + ' ' + escapeHtml(b.currency)
             + '</small></div>';
    });
    return lines.join('');
}

function renderTable(docs) {
    const $tbody = $('#docsTableBody').empty();
    if (!docs.length) {
        $tbody.append(
            '<tr><td colspan="7" class="text-center text-muted">'
            + 'Brak dokumentów dla wybranych filtrów.'
            + '</td></tr>'
        );
        return;
    }
    docs.forEach(function (d) {
        $tbody.append(renderRow(d));
    });
}

// Split a 'YYYY-MM-DD HH:MM:SS' created_at into date + time parts for the
// Utworzony cell. The date renders at default weight; the time renders
// below as muted small text so the column reads as "when" at a glance.
function formatCreatedAt(createdAt) {
    const created = String(createdAt || '');
    const parts   = created.split(' ');
    const datePart = escapeHtml(parts[0] || created);
    const timePart = escapeHtml(parts[1] || '');
    return timePart
        ? datePart + '<br><small class="text-muted">' + timePart + '</small>'
        : datePart;
}

function renderRow(d) {
    const typeBadge = d.type === 'rfq'
        ? '<span class="badge badge-pill badge-secondary" style="font-size: 0.75em;">RFQ</span>'
        : '<span class="badge badge-pill badge-primary" style="font-size: 0.75em;">PO</span>';

    const stateClass = (STATE_BADGE_CLASS[d.type] && STATE_BADGE_CLASS[d.type][d.state])
        || 'badge-secondary';
    const stateLabel = (STATE_BADGE_LABEL[d.type] && STATE_BADGE_LABEL[d.type][d.state])
        || d.state;

    // Number cell — PO gets a vendor_po_number sub-line when it differs.
    let numberCell = '<strong>' + escapeHtml(d.primary_number || '(brak numeru)') + '</strong>';
    if (d.type === 'po' && d.secondary_number && d.secondary_number !== d.primary_number) {
        numberCell += '<div><small class="text-muted">Nr dost.: '
                    + escapeHtml(d.secondary_number) + '</small></div>';
    }

    // Action button — server-stamps the full URL (matching the cart's
    // cart-create-document.php pattern: 'http://' + BASEURL + '/path…').
    // BASEURL may differ across dev / staging / prod, so we never build
    // the URL client-side.
    const editUrl = d.edit_url || ('/admin/purchase/documents/edit?id=' + d.id + '&type=' + d.type);
    let actionCell;
    if (d.editable) {
        actionCell =
            '<a class="btn btn-sm btn-outline-primary" href="' + editUrl
            + '" title="Edytuj dokument"><i class="bi bi-pencil-square"></i> Edytuj</a>';
    } else {
        actionCell =
            '<a class="btn btn-sm btn-outline-secondary" href="' + editUrl
            + '" title="Podgląd dokumentu (tylko do odczytu)"><i class="bi bi-eye"></i> Podgląd</a>';
    }

    return '<tr>'
        + '<td class="text-center">' + typeBadge + '</td>'
        + '<td>' + numberCell + '</td>'
        + '<td>' + escapeHtml(d.vendor_name || '—') + '</td>'
        + '<td class="text-right">' + (d.item_count || 0) + '</td>'
        + '<td>' + formatCreatedAt(d.created_at) + '</td>'
        + '<td class="text-right">' + renderValue(d.value_breakdown) + '</td>'
        + '<td><span class="badge ' + stateClass + '">'
        +       escapeHtml(stateLabel) + '</span></td>'
        + '<td class="text-right" style="white-space: nowrap;">' + actionCell + '</td>'
        + '</tr>';
}

// Pagination widget — lifted from archive-view.js renderPagination() with
// the count label updated to "dokumentów".
function renderPagination() {
    if (totalCount === null) return;
    const $containers = $('#paginationTop, #paginationBottom');
    $containers.empty();
    if (totalCount === 0) return;

    const disp = totalCount;
    const pages = Math.max(1, Math.ceil(disp / itemsPerPage));
    const first = (currentPage - 1) * itemsPerPage + 1;
    const last  = Math.min(currentPage * itemsPerPage, disp);

    let html = '<div class="d-flex flex-column align-items-center mb-3">'
        + '<div class="text-muted small mb-2">Wyświetlanie <strong>'
        + first + '-' + last + '</strong> z <strong>' + disp + '</strong> dokumentów</div>'
        + '<div class="btn-group btn-group-sm">'
        + '<button class="btn btn-outline-primary pagination-btn" data-action="first"'
        + (currentPage === 1 ? ' disabled' : '') + '>'
        + '<i class="bi bi-chevron-double-left"></i></button>'
        + '<button class="btn btn-outline-primary pagination-btn" data-action="prev"'
        + (currentPage === 1 ? ' disabled' : '') + '>'
        + '<i class="bi bi-chevron-left"></i></button>'
        + '<div class="btn-group">'
        + '<button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">'
        + currentPage + '</button>'
        + '<div class="dropdown-menu" style="max-height: 300px; overflow-y: auto;">'
        + buildPageDropdownItems(pages) + '</div>'
        + '</div>'
        + '<button class="btn btn-outline-primary pagination-btn" data-action="next"'
        + (!hasNextPage ? ' disabled' : '') + '>'
        + '<i class="bi bi-chevron-right"></i></button>'
        + '</div></div>';

    $containers.html(html);
    wirePaginationHandlers();
}

function buildPageDropdownItems(totalPages) {
    let html = '';
    for (let p = 1; p <= totalPages; p++) {
        html += '<a class="dropdown-item page-dropdown-item" href="#" data-page="'
              + p + '">' + p + '</a>';
    }
    return html;
}

function wirePaginationHandlers() {
    $('.pagination-btn').on('click', function () {
        const act = $(this).data('action');
        if (act === 'first') currentPage = 1;
        else if (act === 'prev' && currentPage > 1) currentPage--;
        else if (act === 'next' && hasNextPage) currentPage++;
        loadDocuments();
    });
    $('.page-dropdown-item').on('click', function (e) {
        e.preventDefault();
        currentPage = parseInt($(this).data('page'), 10);
        loadDocuments();
    });
}
