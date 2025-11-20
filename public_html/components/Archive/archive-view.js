/**
 * archive-view.js
 * Handles archive view with transfer group rendering and filtering
 */

// State management
let currentPage = 1;
let totalCount = 0;
let hasNextPage = false;
let itemsPerPage = 20;
let isLoading = false;

// Selection tracking for cancellation
let selectedTransferIds = new Set();
let selectedGroupIndexes = new Set();
let groupTransferMap = new Map(); // Maps groupIndex -> Set of transfer IDs

$(document).ready(function() {
    // Initialize Bootstrap components
    $('.selectpicker').selectpicker();

    // Set default values
    $('#noGrouping').prop('checked', true);
    $('#quickNoGrouping').prop('checked', true);

    // Check for FlowPin session filter from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const flowpinSessionParam = urlParams.get('flowpin_session');
    if (flowpinSessionParam) {
        $('#flowpinSession').val(flowpinSessionParam).selectpicker('refresh');
        // Auto-load archive with the session filter
        setTimeout(function() {
            if ($('#quickDeviceType').val() || $('#deviceType').val()) {
                loadArchive();
            }
        }, 500);
    }

    // Attach event handlers
    attachEventHandlers();
});

/**
 * Attach all event handlers for filters and pagination
 */
function attachEventHandlers() {
    // Quick device type change
    $("#quickDeviceType").change(function() {
        const deviceType = $(this).val();
        $("#deviceType").val(deviceType);
        $('.selectpicker').selectpicker('refresh');
        handleDeviceTypeChange(deviceType);
    });

    // Device type change (from filter)
    $("#deviceType").change(function() {
        const deviceType = $(this).val();
        $("#quickDeviceType").val(deviceType);
        handleDeviceTypeChange(deviceType);
    });

    // Device selection change
    $("#list__device").change(function() {
        resetToFirstPage();
        loadArchive();
    });

    // Filter changes
    $("#magazine, #user, #input_type, #flowpinSession").on('hide.bs.select', function() {
        resetToFirstPage();
        loadArchive();
    });

    // Date filters
    $("#dateFrom, #dateTo").change(function() {
        resetToFirstPage();
        loadArchive();
    });

    // Quick checkboxes
    $("#quickShowCancelled").change(function() {
        const isChecked = $(this).prop('checked');
        $("#showCancelled").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    $("#quickNoGrouping").change(function() {
        const isChecked = $(this).prop('checked');
        $("#noGrouping").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    // Filter checkboxes (sync with quick controls)
    $("#showCancelled").change(function() {
        const isChecked = $(this).prop('checked');
        $("#quickShowCancelled").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    $("#noGrouping").change(function() {
        const isChecked = $(this).prop('checked');
        $("#quickNoGrouping").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    // Clear buttons
    $("#clearDevice").click(function() {
        clearDeviceFilter();
    });

    $("#clearMagazineUser").click(function() {
        clearMagazineUserFilter();
    });

    $("#clearInputType").click(function() {
        clearInputTypeFilter();
    });

    $("#clearDates").click(function() {
        clearDatesFilter();
    });

    $("#clearSessionFilter").click(function() {
        $("#flowpinSession").val('').selectpicker('refresh');
        resetToFirstPage();
        loadArchive();
    });

    // Cancel selected button
    $("#cancelSelectedBtn").click(function() {
        handleCancelSelected();
    });

    // Confirm cancel in modal
    $("#confirmCancelTransfers").click(function() {
        $('#cancelTransfersModal').modal('hide');
        cancelSelectedTransfers();
    });

    // Load more entries button (using event delegation for dynamically added elements)
    $(document).on('click', '.load-group-entries', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const groupId = $btn.data('group-id');
        const groupIndex = $btn.data('group-index');
        const offset = parseInt($btn.data('offset'));

        loadGroupEntries(groupId, groupIndex, offset);
    });

    // Pagination handlers will be attached after rendering
}

/**
 * Handle device type change
 */
function handleDeviceTypeChange(deviceType) {
    // Clear device selection
    $("#list__device").empty();

    if (!deviceType) {
        $("#list__device").prop("disabled", true);
        $('.selectpicker').selectpicker('refresh');
        clearTable();
        return;
    }

    // If "all" type selected, disable device select and load data
    if (deviceType === 'all') {
        $("#list__device").prop("disabled", true);
        $('.selectpicker').selectpicker('refresh');
        resetToFirstPage();
        loadArchive();
        return;
    }

    // Clone options from hidden select
    $('#list__' + deviceType + ' option').clone().appendTo('#list__device');

    // Enable device select
    $('#list__device').prop("disabled", false);
    $('.selectpicker').selectpicker('refresh');

    // Load data
    resetToFirstPage();
    loadArchive();
}

/**
 * Clear device filter
 */
function clearDeviceFilter() {
    $('#deviceType').val('');
    $('#quickDeviceType').val('');
    $("#list__device").empty();
    $("#list__device").prop('disabled', true);
    $('.selectpicker').selectpicker('refresh');
    resetToFirstPage();
    clearTable();
}

/**
 * Clear magazine and user filter
 */
function clearMagazineUserFilter() {
    $('#magazine, #user').val([]);
    $('.selectpicker').selectpicker('refresh');
    resetToFirstPage();
    loadArchive();
}

/**
 * Clear input type filter
 */
function clearInputTypeFilter() {
    $('#input_type').val([]);
    $('.selectpicker').selectpicker('refresh');
    resetToFirstPage();
    loadArchive();
}

/**
 * Clear dates filter
 */
function clearDatesFilter() {
    $('#dateFrom, #dateTo').val('');
    resetToFirstPage();
    loadArchive();
}

/**
 * Reset to first page
 */
function resetToFirstPage() {
    currentPage = 1;
}

/**
 * Load archive data via AJAX
 */
function loadArchive() {
    if (isLoading) return;

    const deviceType = $("#quickDeviceType").val() || $("#deviceType").val();

    // If no device type selected, show message and return
    if (!deviceType) {
        clearTable();
        return;
    }

    isLoading = true;
    $("#transferSpinner").show();

    const data = {
        device_type: deviceType,
        device_ids: $("#list__device").val() || [],
        user_ids: $("#user").val() || [],
        magazine_ids: $("#magazine").val() || [],
        input_type_id: $("#input_type").val() || [],
        flowpin_session_id: $("#flowpinSession").val() || null,
        date_from: $("#dateFrom").val() || null,
        date_to: $("#dateTo").val() || null,
        show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0',
        no_grouping: $("#quickNoGrouping").is(':checked') ? '1' : '0',
        page: currentPage
    };

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/archive/archive-table.php",
        data: data,
        dataType: "json",
        success: function(response) {
            renderArchiveTable(response);
            totalCount = response.totalCount;
            hasNextPage = response.hasNextPage;
            renderPagination();
            isLoading = false;
            $("#transferSpinner").hide();
        },
        error: function(xhr, status, error) {
            console.error("Error loading archive:", error);
            showErrorMessage("Błąd podczas ładowania danych archiwum");
            isLoading = false;
            $("#transferSpinner").hide();
        }
    });
}

/**
 * Render archive table with grouped data
 */
function renderArchiveTable(response) {
    const $tbody = $("#archiveTableBody");
    $tbody.empty();

    // Clear selections when rendering new data
    clearSelections();

    if (!response.groups || response.groups.length === 0) {
        $tbody.append(`
            <tr>
                <td colspan="8" class="text-center text-muted">
                    Brak danych do wyświetlenia
                </td>
            </tr>
        `);
        return;
    }

    const noGrouping = $("#quickNoGrouping").is(':checked');

    response.groups.forEach((group, groupIndex) => {
        const collapseClass = `collapse-group-${groupIndex}`;
        const isNoGroup = !group.group_id;
        const userName = `${group.user_name || ''} ${group.user_surname || ''}`.trim();

        if (noGrouping) {
            // No grouping mode - simple rows
            group.entries.forEach(entry => {
                renderSingleRow(entry, groupIndex);
            });
        } else if (isNoGroup) {
            // Individual row that looks like a group but isn't collapsible
            renderIndividualRow(group, groupIndex, userName);
        } else {
            // Build group transfer map for selection logic
            const transferIds = group.entries.map(entry => entry.id);
            groupTransferMap.set(groupIndex, new Set(transferIds));

            // Render collapsible group header
            renderGroupHeader(group, groupIndex, collapseClass, userName);

            // Render detail rows (collapsible)
            group.entries.forEach(entry => {
                renderDetailRow(entry, groupIndex, collapseClass);
            });

            // Add "Load More" button if group has more entries
            if (group.has_more_entries) {
                renderLoadMoreRow(group, groupIndex, collapseClass);
            }
        }
    });

    // Attach collapse event handlers
    attachCollapseHandlers();

    // Attach checkbox event handlers
    attachCheckboxHandlers();

    // Update cancel button visibility
    updateCancelButtonVisibility();
}

/**
 * Render a single row (no grouping mode)
 */
function renderSingleRow(entry, groupIndex) {
    const isCancelled = entry.is_cancelled == 1;
    const rowClass = isCancelled ? 'cancelled-row' : '';
    const checkboxDisabled = isCancelled ? 'disabled' : '';
    const transferGroupInfo = entry.transfer_group_id ? `Grupa #${entry.transfer_group_id}` : '-';
    const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';

    // Get device type for badge
    const deviceType = entry.device_type || $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceTypeBadge = getDeviceTypeBadge(deviceType);

    const row = `
        <tr class="${rowClass}" data-row-id="${entry.id}" data-is-cancelled="${isCancelled ? '1' : '0'}">
            <td class="text-center">
                <div class="custom-control custom-checkbox d-inline-block">
                    <input type="checkbox" class="custom-control-input transfer-checkbox"
                           id="transfer-${entry.id}"
                           data-transfer-id="${entry.id}"
                           ${checkboxDisabled}>
                    <label class="custom-control-label" for="transfer-${entry.id}"></label>
                </div>
            </td>
            <td>${escapeHtml(userName)}</td>
            <td>${escapeHtml(entry.sub_magazine_name)}</td>
            <td>${deviceTypeBadge}${escapeHtml(entry.device_name)}</td>
            <td>${escapeHtml(entry.input_type_name || '')}</td>
            <td>${entry.qty > 0 ? '+' : ''}${parseFloat(entry.qty).toFixed(2)}</td>
            <td>${formatDateTime(entry.timestamp)}</td>
            <td><small>${escapeHtml(entry.comment || '')}</small></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render individual row (looks like group but not collapsible)
 */
function renderIndividualRow(group, groupIndex, userName) {
    const entry = group.entries[0]; // Single entry
    const isCancelled = entry.is_cancelled == 1;
    const groupClass = group.all_cancelled ? 'cancelled-group' : '';
    const checkboxDisabled = isCancelled ? 'disabled' : '';
    const displayUserName = userName || '-';

    // Get device type for badge
    const deviceType = entry.device_type || $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceTypeBadge = getDeviceTypeBadge(deviceType);

    const row = `
        <tr class="group-row ${groupClass}"
            data-group-id=""
            data-group-index="${groupIndex}"
            data-row-id="${entry.id}"
            data-is-cancelled="${isCancelled ? '1' : '0'}">
            <td class="text-center">
                <div class="custom-control custom-checkbox d-inline-block">
                    <input type="checkbox" class="custom-control-input transfer-checkbox"
                           id="transfer-${entry.id}"
                           data-transfer-id="${entry.id}"
                           ${checkboxDisabled}>
                    <label class="custom-control-label" for="transfer-${entry.id}"></label>
                </div>
            </td>
            <td>${escapeHtml(displayUserName)}</td>
            <td>${escapeHtml(entry.sub_magazine_name)}</td>
            <td>${deviceTypeBadge}${escapeHtml(entry.device_name)}</td>
            <td>${escapeHtml(entry.input_type_name || '')}</td>
            <td><strong>${group.total_qty > 0 ? '+' : ''}${parseFloat(group.total_qty).toFixed(2)}</strong></td>
            <td>${formatDateTime(group.group_created_at)}</td>
            <td><small>${escapeHtml(entry.comment || '')}</small></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render group header row (collapsible)
 */
function renderGroupHeader(group, groupIndex, collapseClass, userName) {
    const groupClass = group.all_cancelled ? 'cancelled-group' : '';
    const groupCheckboxDisabled = group.all_cancelled ? 'disabled' : '';
    const entryCount = group.entries_count;
    const entryWord = getPolishPlural(entryCount, 'wpis', 'wpisy', 'wpisów');
    const displayUserName = userName || '-';

    let cancelledBadge = '';
    if (group.has_cancelled && !group.all_cancelled) {
        cancelledBadge = `
            <span class="badge badge-warning badge-cancelled-partial ml-1">
                ${group.cancelled_count} anulowanych
            </span>
        `;
    }

    // Show device types in group when viewing "all" types
    let deviceTypesBadges = '';
    const currentDeviceType = $("#quickDeviceType").val() || $("#deviceType").val();
    if (currentDeviceType === 'all' && group.device_types && group.device_types.length > 0) {
        deviceTypesBadges = ' ' + group.device_types.map(type => getDeviceTypeBadge(type)).join(' ');
    }

    const row = `
        <tr class="group-row ${groupClass}"
            data-toggle="collapse"
            data-target=".${collapseClass}"
            aria-expanded="false"
            aria-controls="${collapseClass}"
            data-group-id="${group.group_id || ''}"
            data-group-index="${groupIndex}">
            <td class="text-center d-flex">
                <div class="custom-control custom-checkbox d-inline-block" onclick="event.stopPropagation();">
                    <input type="checkbox" class="custom-control-input group-checkbox"
                           id="group-${groupIndex}"
                           data-group-id="${group.group_id || ''}"
                           data-group-index="${groupIndex}"
                           ${groupCheckboxDisabled}>
                    <label class="custom-control-label" for="group-${groupIndex}"></label>
                </div>
                <i class="bi bi-chevron-right toggle-icon ml-1"></i>
            </td>
            <td>${escapeHtml(displayUserName)}</td>
            <td colspan="3">
                <strong>Grupa transferowa #${group.group_id || groupIndex}</strong>
                <span class="badge badge-secondary badge-count ml-2">
                    ${entryCount} ${entryWord}
                </span>
                ${cancelledBadge}
                ${deviceTypesBadges}
                ${group.group_notes ? `<br><small class="text-muted">${escapeHtml(group.group_notes)}</small>` : ''}
            </td>
            <td><strong>${group.total_qty > 0 ? '+' : ''}${parseFloat(group.total_qty).toFixed(2)}</strong></td>
            <td>${formatDateTime(group.group_created_at)}</td>
            <td></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render detail row (collapsible)
 */
function renderDetailRow(entry, groupIndex, collapseClass) {
    const isCancelled = entry.is_cancelled == 1;
    const rowClass = isCancelled ? 'cancelled-row' : '';
    const checkboxDisabled = isCancelled ? 'disabled' : '';
    const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';

    // Get device type for badge
    const deviceType = entry.device_type || $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceTypeBadge = getDeviceTypeBadge(deviceType);

    const row = `
        <tr class="collapse ${collapseClass} ${rowClass}"
            data-group-index="${groupIndex}"
            data-row-id="${entry.id}"
            data-is-cancelled="${isCancelled ? '1' : '0'}">
            <td class="text-center indent-cell">
                <div class="custom-control custom-checkbox d-inline-block">
                    <input type="checkbox" class="custom-control-input transfer-checkbox"
                           id="transfer-${entry.id}"
                           data-transfer-id="${entry.id}"
                           data-group-index="${groupIndex}"
                           ${checkboxDisabled}>
                    <label class="custom-control-label" for="transfer-${entry.id}"></label>
                </div>
            </td>
            <td class="indent-cell">${escapeHtml(userName)}</td>
            <td>${escapeHtml(entry.sub_magazine_name)}</td>
            <td>${deviceTypeBadge}${escapeHtml(entry.device_name)}</td>
            <td>${escapeHtml(entry.input_type_name || '')}</td>
            <td>${entry.qty > 0 ? '+' : ''}${parseFloat(entry.qty).toFixed(2)}</td>
            <td>${formatDateTime(entry.timestamp)}</td>
            <td><small>${escapeHtml(entry.comment || '')}</small></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render "Load More" row for groups with many entries
 */
function renderLoadMoreRow(group, groupIndex, collapseClass) {
    const remaining = group.entries_count - group.entries_loaded;
    const row = `
        <tr class="collapse ${collapseClass} load-more-row"
            data-group-id="${group.group_id}"
            data-group-index="${groupIndex}">
            <td colspan="8" class="text-center py-3" style="background-color: #f8f9fa;">
                <button class="btn btn-sm btn-outline-primary load-group-entries"
                        data-group-id="${group.group_id}"
                        data-group-index="${groupIndex}"
                        data-offset="${group.entries_loaded}">
                    <i class="bi bi-arrow-down-circle"></i> Załaduj więcej wpisów
                    <span class="badge badge-secondary ml-1">${remaining} pozostało</span>
                </button>
            </td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Load additional entries for a group via AJAX
 */
function loadGroupEntries(groupId, groupIndex, offset) {
    const deviceType = $("#quickDeviceType").val() || $("#deviceType").val();
    const collapseClass = `collapse-group-${groupIndex}`;

    // Show loading state
    const $btn = $(`.load-group-entries[data-group-id="${groupId}"]`);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Ładowanie...');

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/archive/archive-load-group-entries.php",
        data: {
            transfer_group_id: groupId,
            device_type: deviceType,
            offset: offset,
            limit: 50,
            show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0'
        },
        dataType: "json",
        success: function(response) {
            if (!response.success) {
                showErrorMessage(response.message || 'Błąd podczas ładowania wpisów');
                $btn.prop('disabled', false).html(originalHtml);
                return;
            }

            // Insert new entries before the "Load More" row
            const $loadMoreRow = $(`.load-more-row[data-group-id="${groupId}"]`);

            response.entries.forEach(entry => {
                // Build detail row HTML
                const isCancelled = entry.is_cancelled == 1;
                const rowClass = isCancelled ? 'cancelled-row' : '';
                const checkboxDisabled = isCancelled ? 'disabled' : '';
                const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';
                const deviceTypeBadge = getDeviceTypeBadge(entry.device_type);

                const rowHtml = `
                    <tr class="collapse ${collapseClass} show ${rowClass}"
                        data-group-index="${groupIndex}"
                        data-row-id="${entry.id}"
                        data-is-cancelled="${isCancelled ? '1' : '0'}">
                        <td class="text-center indent-cell">
                            <div class="custom-control custom-checkbox d-inline-block">
                                <input type="checkbox" class="custom-control-input transfer-checkbox"
                                       id="transfer-${entry.id}"
                                       data-transfer-id="${entry.id}"
                                       data-group-index="${groupIndex}"
                                       ${checkboxDisabled}>
                                <label class="custom-control-label" for="transfer-${entry.id}"></label>
                            </div>
                        </td>
                        <td class="indent-cell">${escapeHtml(userName)}</td>
                        <td>${escapeHtml(entry.sub_magazine_name)}</td>
                        <td>${deviceTypeBadge}${escapeHtml(entry.device_name)}</td>
                        <td>${escapeHtml(entry.input_type_name || '')}</td>
                        <td>${entry.qty > 0 ? '+' : ''}${parseFloat(entry.qty).toFixed(2)}</td>
                        <td>${formatDateTime(entry.timestamp)}</td>
                        <td><small>${escapeHtml(entry.comment || '')}</small></td>
                    </tr>
                `;

                // Add to group transfer map for checkbox logic
                if (!groupTransferMap.has(groupIndex)) {
                    groupTransferMap.set(groupIndex, new Set());
                }
                groupTransferMap.get(groupIndex).add(entry.id);

                $loadMoreRow.before(rowHtml);
            });

            // Update or remove "Load More" button
            if (response.hasMore) {
                $btn.prop('disabled', false)
                    .attr('data-offset', offset + response.loaded)
                    .html(`<i class="bi bi-arrow-down-circle"></i> Załaduj więcej wpisów <span class="badge badge-secondary ml-1">${response.remaining} pozostało</span>`);
            } else {
                $loadMoreRow.remove();
            }

            // Re-attach checkbox handlers for new rows
            attachCheckboxHandlers();
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            showErrorMessage('Błąd podczas ładowania wpisów');
            $btn.prop('disabled', false).html('<i class="bi bi-exclamation-triangle"></i> Błąd - spróbuj ponownie');
        }
    });
}

/**
 * Attach collapse event handlers
 */
function attachCollapseHandlers() {
    $('.group-row').off('click').on('click', function(e) {
        const $this = $(this);
        const isExpanded = $this.attr('aria-expanded') === 'true';
        $this.attr('aria-expanded', !isExpanded);
    });
}

/**
 * Render pagination
 */
function renderPagination() {
    const paginationHtml = buildPaginationHtml();

    $("#paginationTop").html(paginationHtml);
    $("#paginationBottom").html(paginationHtml);

    attachPaginationHandlers();
}

/**
 * Build pagination HTML
 */
function buildPaginationHtml() {
    if (totalCount === 0) {
        return '';
    }

    const totalPages = getTotalPages();
    const start = (currentPage - 1) * itemsPerPage + 1;
    const end = Math.min(currentPage * itemsPerPage, totalCount);

    return `
        <div class="d-flex flex-column align-items-center mb-3">
            <div class="text-muted small mb-2">
                Wyświetlanie <strong>${start}-${end}</strong> z <strong>${totalCount}</strong> elementów
                ${totalPages > 0 ? `(Strona ${currentPage} z ${totalPages})` : ''}
            </div>

            <div class="btn-group btn-group-sm" role="group">
                <button class="btn btn-outline-primary pagination-btn"
                        data-action="first"
                        ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="bi bi-chevron-double-left"></i>
                </button>
                <button class="btn btn-outline-primary pagination-btn"
                        data-action="prev"
                        ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="bi bi-chevron-left"></i> Poprzednia
                </button>

                <div class="btn-group" role="group">
                    <button type="button"
                            class="btn btn-primary dropdown-toggle"
                            data-toggle="dropdown"
                            aria-expanded="false">
                            ${currentPage}
                    </button>
                    <div class="dropdown-menu page-dropdown-menu" style="max-height: 300px; overflow-y: auto;">
                        ${buildPageDropdownItems(totalPages)}
                    </div>
                </div>

                <button class="btn btn-outline-primary pagination-btn"
                        data-action="next"
                        ${!hasNextPage || (totalPages > 0 && currentPage >= totalPages) ? 'disabled' : ''}>
                    Następna <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    `;
}

/**
 * Build page dropdown items
 */
function buildPageDropdownItems(totalPages) {
    let items = '';

    if (totalPages > 0) {
        for (let i = 1; i <= totalPages; i++) {
            const isActive = i === currentPage ? 'active' : '';
            items += `
                <a class="dropdown-item page-dropdown-item ${isActive}"
                   href="#"
                   data-page="${i}">
                   ${i}
                </a>
            `;
        }
    } else {
        const pagesToShow = Math.max(currentPage + 10, 20);

        for (let i = 1; i <= pagesToShow; i++) {
            const isActive = i === currentPage ? 'active' : '';
            items += `
                <a class="dropdown-item page-dropdown-item ${isActive}"
                   href="#"
                   data-page="${i}">
                    Strona ${i}
                </a>
            `;
        }
    }

    return items;
}

/**
 * Get total pages
 */
function getTotalPages() {
    if (totalCount === 0) return 0;
    return Math.ceil(totalCount / itemsPerPage);
}

/**
 * Attach pagination handlers
 */
function attachPaginationHandlers() {
    // Pagination button clicks
    $('.pagination-btn').off('click').on('click', function() {
        const action = $(this).data('action');

        switch(action) {
            case 'first':
                currentPage = 1;
                loadArchive();
                break;
            case 'prev':
                if (currentPage > 1) {
                    currentPage--;
                    loadArchive();
                }
                break;
            case 'next':
                if (hasNextPage) {
                    currentPage++;
                    loadArchive();
                }
                break;
        }
    });

    // Page dropdown clicks
    $('.page-dropdown-item').off('click').on('click', function(e) {
        e.preventDefault();
        const page = parseInt($(this).data('page'));
        if (page !== currentPage) {
            currentPage = page;
            loadArchive();
        }
    });
}

/**
 * Clear table
 */
function clearTable() {
    $("#archiveTableBody").html(`
        <tr>
            <td colspan="8" class="text-center text-muted">
                Wybierz typ urządzenia aby wyświetlić historię transferów
            </td>
        </tr>
    `);
    $("#paginationTop, #paginationBottom").html('');
}

/**
 * Get Polish plural form
 */
function getPolishPlural(count, singular, few, many) {
    if (count === 1) return singular;
    if (count >= 2 && count <= 4) return few;
    return many;
}

/**
 * Format date time
 */
function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '';
    return dateTimeString.replace(' ', '<br><small>') + '</small>';
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

/**
 * Get device type badge HTML
 * @param {string} deviceType - The device type (sku, tht, smd, parts)
 * @returns {string} Badge HTML
 */
function getDeviceTypeBadge(deviceType) {
    if (!deviceType) return '';

    const badgeClasses = {
        'sku': 'badge-primary',
        'tht': 'badge-success',
        'smd': 'badge-info',
        'parts': 'badge-warning'
    };

    const badgeClass = badgeClasses[deviceType.toLowerCase()] || 'badge-secondary';
    const typeLabel = deviceType.toUpperCase();

    return `<span class="badge ${badgeClass} badge-device-type">${typeLabel}</span>`;
}

/**
 * Show error message
 */
function showErrorMessage(message) {
    const alertHtml = `
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;

    $("#ajaxResult").append(alertHtml);

    setTimeout(function() {
        $(".alert-danger").alert('close');
    }, 5000);
}

/**
 * Show success message
 */
function showSuccessMessage(message) {
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;

    $("#ajaxResult").append(alertHtml);

    setTimeout(function() {
        $(".alert-success").alert('close');
    }, 3000);
}

/**
 * Clear all selections
 */
function clearSelections() {
    selectedTransferIds.clear();
    selectedGroupIndexes.clear();
    groupTransferMap.clear();
}

/**
 * Attach checkbox event handlers
 */
function attachCheckboxHandlers() {
    // Group checkbox handler
    $('.group-checkbox').off('change').on('change', function(e) {
        const groupIndex = parseInt($(this).data('group-index'));
        const isChecked = $(this).prop('checked');
        handleGroupCheckboxChange(groupIndex, isChecked);
    });

    // Transfer checkbox handler
    $('.transfer-checkbox').off('change').on('change', function(e) {
        const transferId = parseInt($(this).data('transfer-id'));
        const groupIndex = $(this).data('group-index');
        const isChecked = $(this).prop('checked');
        handleTransferCheckboxChange(transferId, groupIndex, isChecked);
    });
}

/**
 * Handle group checkbox change
 */
function handleGroupCheckboxChange(groupIndex, isChecked) {
    const transferIds = groupTransferMap.get(groupIndex);

    if (!transferIds) return;

    if (isChecked) {
        // Count how many transfers are selectable (exist and not cancelled)
        let selectableCount = 0;
        transferIds.forEach(id => {
            const $checkbox = $(`#transfer-${id}`);
            if ($checkbox.length && !$checkbox.prop('disabled')) {
                selectableCount++;
            }
        });

        // If there's at least one selectable transfer, proceed
        if (selectableCount > 0) {
            // Select all child transfers that are not cancelled
            transferIds.forEach(id => {
                const $checkbox = $(`#transfer-${id}`);
                if ($checkbox.length && !$checkbox.prop('disabled')) {
                    selectedTransferIds.add(id);
                    $checkbox.prop('checked', true);
                }
            });
            selectedGroupIndexes.add(groupIndex);
        } else {
            // Cannot select group - uncheck it (all transfers are cancelled)
            $(`#group-${groupIndex}`).prop('checked', false);
        }
    } else {
        // Deselect all child transfers
        transferIds.forEach(id => {
            selectedTransferIds.delete(id);
            $(`#transfer-${id}`).prop('checked', false);
        });
        selectedGroupIndexes.delete(groupIndex);
    }

    updateCancelButtonVisibility();
}

/**
 * Handle transfer checkbox change
 */
function handleTransferCheckboxChange(transferId, groupIndex, isChecked) {
    if (isChecked) {
        // Don't allow selection of cancelled transfers
        const $row = $(`tr[data-row-id="${transferId}"]`);
        if ($row.attr('data-is-cancelled') === '1') {
            $(`#transfer-${transferId}`).prop('checked', false);
            return;
        }

        selectedTransferIds.add(transferId);

        // Check if this transfer belongs to a group
        if (groupIndex !== undefined && groupIndex !== null && groupIndex !== '') {
            const groupTransfers = groupTransferMap.get(parseInt(groupIndex));

            if (groupTransfers) {
                // Check if ALL non-cancelled siblings are now selected
                let allSelected = true;
                groupTransfers.forEach(id => {
                    const $checkbox = $(`#transfer-${id}`);
                    // Skip cancelled transfers
                    if (!$checkbox.prop('disabled') && !selectedTransferIds.has(id)) {
                        allSelected = false;
                    }
                });

                if (allSelected) {
                    // Check the group checkbox
                    selectedGroupIndexes.add(parseInt(groupIndex));
                    $(`#group-${groupIndex}`).prop('checked', true);
                }
            }
        }
    } else {
        selectedTransferIds.delete(transferId);

        // If this transfer belongs to a group, uncheck the group
        if (groupIndex !== undefined && groupIndex !== null && groupIndex !== '') {
            selectedGroupIndexes.delete(parseInt(groupIndex));
            $(`#group-${groupIndex}`).prop('checked', false);
        }
    }

    updateCancelButtonVisibility();
}

/**
 * Update cancel button visibility
 */
function updateCancelButtonVisibility() {
    const selectedCount = selectedTransferIds.size;
    const $cancelBtn = $('#cancelSelectedBtn');

    if (selectedCount > 0) {
        $cancelBtn.show();
        $cancelBtn.find('.badge').text(selectedCount);
    } else {
        $cancelBtn.hide();
    }
}

/**
 * Handle cancel selected transfers
 */
function handleCancelSelected() {
    const selectedCount = selectedTransferIds.size;

    if (selectedCount === 0) {
        showErrorMessage('Nie zaznaczono żadnych transferów do anulowania');
        return;
    }

    // Populate the modal with transfer details
    populateCancelModal();

    // Show the modal
    $('#cancelTransfersModal').modal('show');
}

/**
 * Populate cancel modal with transfer details
 */
function populateCancelModal() {
    const selectedCount = selectedTransferIds.size;

    // Update count badge
    $('#cancelCount').text(selectedCount);

    // Clear and populate summary table
    const $summaryBody = $('#cancelSummaryBody');
    $summaryBody.empty();

    // Collect transfer data from the table
    selectedTransferIds.forEach(transferId => {
        const $row = $(`tr[data-row-id="${transferId}"]`);

        if ($row.length > 0) {
            // Extract data from the row
            const $cells = $row.find('td');

            // Get text content from cells (skip checkbox cell at index 0)
            const userName = $cells.eq(1).text().trim();
            const magazineName = $cells.eq(2).text().trim();
            const deviceName = $cells.eq(3).text().trim();
            const inputType = $cells.eq(4).text().trim();
            const qty = $cells.eq(5).text().trim();
            const date = $cells.eq(6).html().trim(); // Use html() to preserve formatting

            // Create summary row
            const summaryRow = `
                <tr>
                    <td>${escapeHtml(userName)}</td>
                    <td>${escapeHtml(magazineName)}</td>
                    <td>${escapeHtml(deviceName)}</td>
                    <td>${escapeHtml(inputType)}</td>
                    <td>${qty}</td>
                    <td>${date}</td>
                </tr>
            `;

            $summaryBody.append(summaryRow);
        }
    });
}

/**
 * Cancel selected transfers via AJAX
 */
function cancelSelectedTransfers() {
    const transferIds = Array.from(selectedTransferIds);
    const deviceType = $("#quickDeviceType").val() || $("#deviceType").val();

    // Show loading state
    const $cancelBtn = $('#cancelSelectedBtn');
    const originalHtml = $cancelBtn.html();
    $cancelBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2"></span>Anulowanie...');

    $.ajax({
        type: 'POST',
        url: COMPONENTS_PATH + '/archive/cancel-transfer.php',
        data: {
            action: 'cancel_transfers',
            transfer_ids: JSON.stringify(transferIds),
            device_type: deviceType
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showSuccessMessage(response.message || 'Transfery zostały pomyślnie anulowane');

                // Clear selections
                clearSelections();
                updateCancelButtonVisibility();

                // Reload archive table
                loadArchive();
            } else {
                showErrorMessage('Błąd: ' + (response.message || 'Nie udało się anulować transferów'));
            }

            // Reset button state
            $cancelBtn.prop('disabled', false).html(originalHtml);
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.log('Response text:', xhr.responseText);
            showErrorMessage('Błąd podczas anulowania transferów');

            // Reset button state
            $cancelBtn.prop('disabled', false).html(originalHtml);
        }
    });
}
