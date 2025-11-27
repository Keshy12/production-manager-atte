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
let selectedTransferIds = new Map(); // Maps deviceType -> Set of transfer IDs (e.g., 'tht' -> Set([1,2,3]))
let selectedGroupIndexes = new Set();
let groupTransferMap = new Map(); // Maps groupIndex -> Map of deviceType -> Set of transfer IDs
let deviceTransferMap = new Map(); // Maps "groupIndex-deviceIndex" -> Set of transfer IDs
let deviceUnloadedSelections = new Map(); // Maps "groupIndex-deviceIndex" -> boolean (true if unloaded rows selected)
let deviceUnloadedInfo = new Map(); // Maps "groupIndex-deviceIndex" -> {groupId, deviceId, deviceType, totalCount, loadedCount}
let excludedUnloadedTransferIds = new Set(); // IDs of unloaded transfers that user unchecked in modal

$(document).ready(function() {
    // Initialize Bootstrap components
    $('.selectpicker').selectpicker();

    // Set default values
    $('#noGrouping').prop('checked', true);
    $('#quickNoGrouping').prop('checked', true);

    // Set default date from to one month ago (for optimization)
    const oneMonthAgo = new Date();
    oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);
    $('#dateFrom').val(oneMonthAgo.toISOString().split('T')[0]);

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

    // Filter changes - only reload when value actually changes
    $("#magazine, #user, #input_type, #flowpinSession").on('change', function() {
        resetToFirstPage();
        loadArchive();
    });

    // Refresh button
    $("#refreshArchive").click(function() {
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

    // Load more device entries button (for specific device within a group)
    $(document).on('click', '.load-device-entries', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const groupId = $btn.data('group-id');
        const groupIndex = $btn.data('group-index');
        const deviceId = $btn.data('device-id');
        const deviceType = $btn.data('device-type');
        const deviceIndex = $btn.data('device-index');
        const offset = parseInt($btn.data('offset'));

        loadDeviceEntries(groupId, groupIndex, deviceId, deviceType, deviceIndex, offset);
    });

    // Load all device entries button (for specific device within a group)
    $(document).on('click', '.load-all-device-entries', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const groupId = $btn.data('group-id');
        const groupIndex = $btn.data('group-index');
        const deviceId = $btn.data('device-id');
        const deviceType = $btn.data('device-type');
        const deviceIndex = $btn.data('device-index');
        const offset = parseInt($btn.data('offset'));
        const remaining = parseInt($btn.data('remaining'));

        loadAllDeviceEntries(groupId, groupIndex, deviceId, deviceType, deviceIndex, offset, remaining);
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

            // Render collapsible group header (Level 1)
            renderGroupHeader(group, groupIndex, collapseClass, userName);

            // Check if we have device aggregation data
            if (group.devices && group.devices.length > 0) {
                // Render device rows (Level 2) with their entries (Level 3)
                group.devices.forEach((device, deviceIndex) => {
                    const deviceCollapseClass = `collapse-device-${groupIndex}-${deviceIndex}`;
                    const deviceKey = `${groupIndex}-${deviceIndex}`;

                    // Build device transfer map for selection logic
                    const deviceTransferIds = device.entries.map(entry => entry.id);
                    deviceTransferMap.set(deviceKey, new Set(deviceTransferIds));

                    // Render device summary row (visible when group expanded)
                    renderDeviceRow(device, groupIndex, deviceIndex, collapseClass, deviceCollapseClass, group.group_id);

                    // Render individual transfer entries within this device (collapsed by default, only device collapse class)
                    device.entries.forEach(entry => {
                        renderDetailRow(entry, groupIndex, deviceCollapseClass, 2, deviceIndex);
                    });

                    // Add "Load More" button for this device if needed (also only device collapse class)
                    if (device.has_more_entries) {
                        renderDeviceLoadMoreRow(device, group.group_id, groupIndex, deviceIndex, deviceCollapseClass);
                    }
                });
            } else {
                // Fallback to original behavior if no device aggregation
                group.entries.forEach(entry => {
                    renderDetailRow(entry, groupIndex, collapseClass, 1);
                });

                // Add "Load More" button if group has more entries
                if (group.has_more_entries) {
                    renderLoadMoreRow(group, groupIndex, collapseClass);
                }
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
        <tr class="${rowClass}" data-row-id="${entry.id}" data-device-type="${deviceType}" data-is-cancelled="${isCancelled ? '1' : '0'}">
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
            data-device-type="${deviceType}"
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

    // Device count info
    let deviceCountInfo = '';
    if (group.devices && group.devices.length > 0) {
        const deviceCount = group.devices.length;
        const deviceWord = getPolishPlural(deviceCount, 'urządzenie', 'urządzenia', 'urządzeń');
        deviceCountInfo = `<span class="badge badge-info ml-1">${deviceCount} ${deviceWord}</span>`;
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
                ${cancelledBadge}
                ${group.group_notes ? `<br><small class="text-muted">${escapeHtml(group.group_notes)}</small>` : ''}
            </td>
            <td>
                <span class="badge badge-secondary badge-count">${entryCount} ${entryWord}</span>
                ${deviceCountInfo}
            </td>
            <td>${formatDateTime(group.group_created_at)}</td>
            <td></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render detail row (collapsible)
 * @param {object} entry - The transfer entry data
 * @param {number} groupIndex - The group index
 * @param {string} collapseClass - CSS classes for collapse behavior
 * @param {number} indentLevel - Indentation level (1 = device level, 2 = transfer level)
 * @param {number|null} deviceIndex - Device index within the group (optional)
 */
function renderDetailRow(entry, groupIndex, collapseClass, indentLevel = 1, deviceIndex = null) {
    const isCancelled = entry.is_cancelled == 1;
    const rowClass = isCancelled ? 'cancelled-row' : '';
    const checkboxDisabled = isCancelled ? 'disabled' : '';
    const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';
    const indentClass = indentLevel === 2 ? 'indent-cell-2' : 'indent-cell';

    // Get device type for badge
    let deviceType = entry.device_type || $("#quickDeviceType").val() || $("#deviceType").val();

    // Ensure deviceType is valid (not 'all' or undefined)
    if (!deviceType || deviceType === 'all' || deviceType === 'undefined') {
        deviceType = 'sku'; // Default fallback
    }

    const deviceTypeBadge = getDeviceTypeBadge(deviceType);

    const deviceIndexAttr = deviceIndex !== null ? `data-device-index="${deviceIndex}"` : '';

    const row = `
        <tr class="collapse ${collapseClass} ${rowClass} detail-row-level-${indentLevel}"
            data-group-index="${groupIndex}"
            data-row-id="${entry.id}"
            data-device-type="${deviceType}"
            data-is-cancelled="${isCancelled ? '1' : '0'}"
            ${deviceIndexAttr}>
            <td class="text-center ${indentClass}">
                <div class="custom-control custom-checkbox d-inline-block">
                    <input type="checkbox" class="custom-control-input transfer-checkbox"
                           id="transfer-${entry.id}"
                           data-transfer-id="${entry.id}"
                           data-device-type="${deviceType}"
                           data-group-index="${groupIndex}"
                           data-device-index="${deviceIndex !== null ? deviceIndex : ''}"
                           ${checkboxDisabled}>
                    <label class="custom-control-label" for="transfer-${entry.id}"></label>
                </div>
            </td>
            <td class="${indentClass}">${escapeHtml(userName)}</td>
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
 * Render device summary row (Level 2 - collapsible)
 * Shows aggregated quantity for a specific device within a transfer group
 */
function renderDeviceRow(device, groupIndex, deviceIndex, groupCollapseClass, deviceCollapseClass, groupId) {
    const deviceType = device.device_type || $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceTypeBadge = getDeviceTypeBadge(deviceType);
    const entriesCount = device.total_entries_count || device.entries_count;
    const entriesLoaded = device.entries_loaded || device.entries.length;
    const entryWord = getPolishPlural(entriesCount, 'wpis', 'wpisy', 'wpisów');

    // Determine if device checkbox should be disabled (all entries are cancelled)
    const deviceCheckboxDisabled = device.all_cancelled ? 'disabled' : '';

    // Show cancelled count badge if there are cancelled entries but not all are cancelled
    let cancelledBadge = '';
    const hasCancelled = device.total_cancelled_count && device.total_cancelled_count > 0;
    if (hasCancelled && !device.all_cancelled) {
        cancelledBadge = `
            <span class="badge badge-warning badge-cancelled-partial ml-1">
                ${device.total_cancelled_count} anulowanych
            </span>
        `;
    }

    const row = `
        <tr class="collapse ${groupCollapseClass} device-row"
            data-toggle="collapse"
            data-target=".${deviceCollapseClass}"
            aria-expanded="false"
            aria-controls="${deviceCollapseClass}"
            data-group-index="${groupIndex}"
            data-device-index="${deviceIndex}"
            data-device-id="${device.device_id}"
            data-device-type="${device.device_type}">
            <td class="text-center indent-cell" onclick="event.stopPropagation();">
                <div class="custom-control custom-checkbox d-inline-block">
                    <input type="checkbox" class="custom-control-input device-checkbox"
                           id="device-${groupIndex}-${deviceIndex}"
                           data-group-index="${groupIndex}"
                           data-device-index="${deviceIndex}"
                           ${deviceCheckboxDisabled}>
                    <label class="custom-control-label" for="device-${groupIndex}-${deviceIndex}"></label>
                </div>
            </td>
            <td class="indent-cell">
                <i class="bi bi-chevron-right toggle-icon-device"></i>
            </td>
            <td colspan="2">
                <strong>${escapeHtml(device.device_name)}</strong>
                <span class="badge badge-light ml-1 device-entries-badge"
                      id="device-badge-${groupIndex}-${deviceIndex}"
                      data-total="${entriesCount}">${entriesLoaded}/${entriesCount} ${entryWord}</span>
                ${cancelledBadge}
            </td>
            <td></td>
            <td>${deviceTypeBadge}<strong>${device.total_qty > 0 ? '+' : ''}${parseFloat(device.total_qty).toFixed(2)}</strong></td>
            <td></td>
            <td></td>
        </tr>
    `;

    $("#archiveTableBody").append(row);
}

/**
 * Render "Load More" row for a specific device within a group
 */
function renderDeviceLoadMoreRow(device, groupId, groupIndex, deviceIndex, deviceCollapseClass) {
    const remaining = device.total_entries_count - device.entries_loaded;
    const deviceKey = `${groupIndex}-${deviceIndex}`;

    // Store unloaded info for later use
    deviceUnloadedInfo.set(deviceKey, {
        groupId: groupId,
        deviceId: device.device_id,
        deviceType: device.device_type,
        totalCount: device.total_entries_count,
        loadedCount: device.entries_loaded,
        unloadedActiveCount: device.unloaded_active_count || 0,
        unloadedCancelledCount: device.unloaded_cancelled_count || 0
    });

    // Only show unloaded checkbox if there are active unloaded entries
    const showUnloadedCheckbox = !device.all_unloaded_cancelled;

    // Build badge with active vs cancelled breakdown
    let badgeHtml = '';
    if (device.unloaded_active_count > 0 && device.unloaded_cancelled_count > 0) {
        badgeHtml = `
            <span class="badge badge-secondary ml-1">${device.unloaded_active_count} aktywnych</span>
            <span class="badge badge-warning ml-1">${device.unloaded_cancelled_count} anulowanych</span>
        `;
    } else if (device.unloaded_active_count > 0) {
        badgeHtml = `<span class="badge badge-secondary ml-1">${device.unloaded_active_count} pozostało</span>`;
    } else if (device.unloaded_cancelled_count > 0) {
        badgeHtml = `<span class="badge badge-warning ml-1">${device.unloaded_cancelled_count} anulowanych</span>`;
    } else {
        badgeHtml = `<span class="badge badge-secondary ml-1">${remaining} pozostało</span>`;
    }

    const row = `
        <tr class="collapse ${deviceCollapseClass} load-more-row load-more-device-row"
            data-group-id="${groupId}"
            data-group-index="${groupIndex}"
            data-device-id="${device.device_id}"
            data-device-type="${device.device_type}"
            data-device-index="${deviceIndex}"
            data-remaining="${remaining}">
            <td class="text-center indent-cell-2" onclick="event.stopPropagation();">
                ${showUnloadedCheckbox ? `
                    <div class="custom-control custom-checkbox d-inline-block">
                        <input type="checkbox" class="custom-control-input unloaded-checkbox"
                               id="unloaded-${groupIndex}-${deviceIndex}"
                               data-group-id="${groupId}"
                               data-group-index="${groupIndex}"
                               data-device-id="${device.device_id}"
                               data-device-type="${device.device_type}"
                               data-device-index="${deviceIndex}">
                        <label class="custom-control-label" for="unloaded-${groupIndex}-${deviceIndex}"></label>
                    </div>
                ` : ''}
            </td>
            <td colspan="7" class="text-center py-2">
                <button class="btn btn-sm btn-outline-secondary load-device-entries"
                        data-group-id="${groupId}"
                        data-group-index="${groupIndex}"
                        data-device-id="${device.device_id}"
                        data-device-type="${device.device_type}"
                        data-device-index="${deviceIndex}"
                        data-offset="${device.entries_loaded}">
                    <i class="bi bi-arrow-down-circle"></i> Załaduj więcej
                    ${badgeHtml}
                </button>
                <button class="btn btn-sm btn-outline-primary ml-2 load-all-device-entries"
                        data-group-id="${groupId}"
                        data-group-index="${groupIndex}"
                        data-device-id="${device.device_id}"
                        data-device-type="${device.device_type}"
                        data-device-index="${deviceIndex}"
                        data-offset="${device.entries_loaded}"
                        data-remaining="${remaining}">
                    <i class="bi bi-arrow-down-square"></i> Załaduj wszystkie
                </button>
            </td>
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
                    .data('offset', offset + response.loaded)
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
 * Load additional entries for a specific device within a group via AJAX
 */
function loadDeviceEntries(groupId, groupIndex, deviceId, deviceTypeFilter, deviceIndex, offset) {
    const deviceType = $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceCollapseClass = `collapse-device-${groupIndex}-${deviceIndex}`;

    // Show loading state
    const $btn = $(`.load-device-entries[data-group-id="${groupId}"][data-device-id="${deviceId}"]`);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Ładowanie...');

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/archive/archive-load-group-entries.php",
        data: {
            transfer_group_id: groupId,
            device_type: deviceType,
            device_id: deviceId,
            device_type_filter: deviceTypeFilter,
            offset: offset,
            limit: 10,
            show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0'
        },
        dataType: "json",
        success: function(response) {
            if (!response.success) {
                showErrorMessage(response.message || 'Błąd podczas ładowania wpisów');
                $btn.prop('disabled', false).html(originalHtml);
                return;
            }

            // Insert new entries before the "Load More" row for this device
            const $loadMoreRow = $(`.load-more-device-row[data-group-id="${groupId}"][data-device-id="${deviceId}"]`);

            const deviceKey = `${groupIndex}-${deviceIndex}`;
            const isUnloadedSelected = deviceUnloadedSelections.has(deviceKey);

            response.entries.forEach(entry => {
                // Build detail row HTML
                const isCancelled = entry.is_cancelled == 1;
                const rowClass = isCancelled ? 'cancelled-row' : '';
                const checkboxDisabled = isCancelled ? 'disabled' : '';
                const checkboxChecked = (isUnloadedSelected && !isCancelled) ? 'checked' : '';
                const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';
                const deviceTypeBadge = getDeviceTypeBadge(entry.device_type);

                const rowHtml = `
                    <tr class="collapse ${deviceCollapseClass} show ${rowClass} detail-row-level-2"
                        data-group-index="${groupIndex}"
                        data-device-index="${deviceIndex}"
                        data-row-id="${entry.id}"
                        data-device-type="${entry.device_type}"
                        data-is-cancelled="${isCancelled ? '1' : '0'}">
                        <td class="text-center indent-cell-2">
                            <div class="custom-control custom-checkbox d-inline-block">
                                <input type="checkbox" class="custom-control-input transfer-checkbox"
                                       id="transfer-${entry.id}"
                                       data-transfer-id="${entry.id}"
                                       data-device-type="${entry.device_type}"
                                       data-group-index="${groupIndex}"
                                       data-device-index="${deviceIndex}"
                                       ${checkboxDisabled}
                                       ${checkboxChecked}>
                                <label class="custom-control-label" for="transfer-${entry.id}"></label>
                            </div>
                        </td>
                        <td class="indent-cell-2">${escapeHtml(userName)}</td>
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

                // Add to device transfer map for checkbox logic
                if (!deviceTransferMap.has(deviceKey)) {
                    deviceTransferMap.set(deviceKey, new Set());
                }
                deviceTransferMap.get(deviceKey).add(entry.id);

                // If unloaded checkbox is checked, add to selected transfers
                if (isUnloadedSelected && !isCancelled) {
                    const entryDeviceType = entry.device_type;
                    if (!selectedTransferIds.has(entryDeviceType)) {
                        selectedTransferIds.set(entryDeviceType, new Set());
                    }
                    selectedTransferIds.get(entryDeviceType).add(entry.id);
                }

                $loadMoreRow.before(rowHtml);
            });

            // Update or remove "Load More" button
            if (response.hasMore) {
                // Build updated badge with active vs cancelled breakdown
                let badgeHtml = '';
                if (response.remaining_active > 0 && response.remaining_cancelled > 0) {
                    badgeHtml = `
                        <span class="badge badge-secondary ml-1">${response.remaining_active} aktywnych</span>
                        <span class="badge badge-warning ml-1">${response.remaining_cancelled} anulowanych</span>
                    `;
                } else if (response.remaining_active > 0) {
                    badgeHtml = `<span class="badge badge-secondary ml-1">${response.remaining_active} pozostało</span>`;
                } else if (response.remaining_cancelled > 0) {
                    badgeHtml = `<span class="badge badge-warning ml-1">${response.remaining_cancelled} anulowanych</span>`;
                } else {
                    badgeHtml = `<span class="badge badge-secondary ml-1">${response.remaining} pozostało</span>`;
                }

                $btn.prop('disabled', false)
                    .data('offset', offset + response.loaded)
                    .html(`<i class="bi bi-arrow-down-circle"></i> Załaduj więcej ${badgeHtml}`);

                // Also update the "Load All" button's remaining count and offset
                const $loadAllBtn = $loadMoreRow.find('.load-all-device-entries');
                $loadAllBtn.data('offset', offset + response.loaded);
                $loadAllBtn.data('remaining', response.remaining);

                // Remove unloaded checkbox if all remaining are cancelled
                if (response.remaining_active === 0 && response.remaining_cancelled > 0) {
                    const $unloadedCheckbox = $(`#unloaded-${groupIndex}-${deviceIndex}`);
                    $unloadedCheckbox.closest('.custom-control').remove();
                }
            } else {
                $loadMoreRow.remove();
            }

            // Update device entries badge to show new loaded/total count
            const $badge = $(`#device-badge-${groupIndex}-${deviceIndex}`);
            const totalEntries = parseInt($badge.data('total'));
            const newLoaded = offset + response.loaded;
            const entryWord = getPolishPlural(totalEntries, 'wpis', 'wpisy', 'wpisów');
            $badge.text(`${newLoaded}/${totalEntries} ${entryWord}`);

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
 * Load ALL remaining entries for a device via AJAX
 */
function loadAllDeviceEntries(groupId, groupIndex, deviceId, deviceTypeFilter, deviceIndex, offset, remaining) {
    const deviceType = $("#quickDeviceType").val() || $("#deviceType").val();
    const deviceCollapseClass = `collapse-device-${groupIndex}-${deviceIndex}`;

    // Show loading state on both buttons
    const $loadMoreRow = $(`.load-more-device-row[data-group-id="${groupId}"][data-device-id="${deviceId}"]`);
    const $btn = $loadMoreRow.find('.load-all-device-entries');
    const $loadMoreBtn = $loadMoreRow.find('.load-device-entries');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Ładowanie...');
    $loadMoreBtn.prop('disabled', true);

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/archive/archive-load-group-entries.php",
        data: {
            transfer_group_id: groupId,
            device_type: deviceType,
            device_id: deviceId,
            device_type_filter: deviceTypeFilter,
            offset: offset,
            limit: remaining + 10, // Load all remaining plus some buffer
            show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0'
        },
        dataType: "json",
        success: function(response) {
            if (!response.success) {
                showErrorMessage(response.message || 'Błąd podczas ładowania wpisów');
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-down-square"></i> Załaduj wszystkie');
                $loadMoreBtn.prop('disabled', false);
                return;
            }

            const deviceKey = `${groupIndex}-${deviceIndex}`;
            const isUnloadedSelected = deviceUnloadedSelections.has(deviceKey);

            response.entries.forEach(entry => {
                // Build detail row HTML
                const isCancelled = entry.is_cancelled == 1;
                const rowClass = isCancelled ? 'cancelled-row' : '';
                const checkboxDisabled = isCancelled ? 'disabled' : '';
                const checkboxChecked = (isUnloadedSelected && !isCancelled) ? 'checked' : '';
                const userName = `${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-';
                const deviceTypeBadge = getDeviceTypeBadge(entry.device_type);

                const rowHtml = `
                    <tr class="collapse ${deviceCollapseClass} show ${rowClass} detail-row-level-2"
                        data-group-index="${groupIndex}"
                        data-device-index="${deviceIndex}"
                        data-row-id="${entry.id}"
                        data-device-type="${entry.device_type}"
                        data-is-cancelled="${isCancelled ? '1' : '0'}">
                        <td class="text-center indent-cell-2">
                            <div class="custom-control custom-checkbox d-inline-block">
                                <input type="checkbox" class="custom-control-input transfer-checkbox"
                                       id="transfer-${entry.id}"
                                       data-transfer-id="${entry.id}"
                                       data-device-type="${entry.device_type}"
                                       data-group-index="${groupIndex}"
                                       data-device-index="${deviceIndex}"
                                       ${checkboxDisabled}
                                       ${checkboxChecked}>
                                <label class="custom-control-label" for="transfer-${entry.id}"></label>
                            </div>
                        </td>
                        <td class="indent-cell-2">${escapeHtml(userName)}</td>
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

                // Add to device transfer map for checkbox logic
                if (!deviceTransferMap.has(deviceKey)) {
                    deviceTransferMap.set(deviceKey, new Set());
                }
                deviceTransferMap.get(deviceKey).add(entry.id);

                // If unloaded checkbox is checked, add to selected transfers
                if (isUnloadedSelected && !isCancelled) {
                    const entryDeviceType = entry.device_type;
                    if (!selectedTransferIds.has(entryDeviceType)) {
                        selectedTransferIds.set(entryDeviceType, new Set());
                    }
                    selectedTransferIds.get(entryDeviceType).add(entry.id);
                }

                $loadMoreRow.before(rowHtml);
            });

            // Always remove the "Load More" row since we loaded all
            $loadMoreRow.remove();

            // Remove unloaded selection tracking since all rows are now loaded
            deviceUnloadedSelections.delete(deviceKey);
            deviceUnloadedInfo.delete(deviceKey);

            // Update device entries badge to show all loaded (total/total)
            const $badge = $(`#device-badge-${groupIndex}-${deviceIndex}`);
            const totalEntries = parseInt($badge.data('total'));
            const entryWord = getPolishPlural(totalEntries, 'wpis', 'wpisy', 'wpisów');
            $badge.text(`${totalEntries}/${totalEntries} ${entryWord}`);

            // Re-attach checkbox handlers for new rows
            attachCheckboxHandlers();
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            showErrorMessage('Błąd podczas ładowania wpisów');
            $btn.prop('disabled', false).html('<i class="bi bi-exclamation-triangle"></i> Błąd - spróbuj ponownie');
            $loadMoreBtn.prop('disabled', false);
        }
    });
}

/**
 * Attach collapse event handlers
 */
function attachCollapseHandlers() {
    // Group row collapse handler
    $('.group-row').off('click').on('click', function(e) {
        const $this = $(this);
        const isExpanded = $this.attr('aria-expanded') === 'true';
        $this.attr('aria-expanded', !isExpanded);

        // When collapsing group, also collapse all device rows inside
        if (isExpanded) {
            const groupIndex = $this.data('group-index');
            // Find all device rows for this group and collapse them
            $(`.device-row[data-group-index="${groupIndex}"]`).each(function() {
                const $deviceRow = $(this);
                if ($deviceRow.attr('aria-expanded') === 'true') {
                    $deviceRow.attr('aria-expanded', 'false');
                    // Hide the device's child entries
                    const deviceIndex = $deviceRow.data('device-index');
                    $(`.collapse-device-${groupIndex}-${deviceIndex}`).removeClass('show');
                }
            });
        }
    });

    // Device row collapse handler
    $('.device-row').off('click').on('click', function(e) {
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
    deviceTransferMap.clear();
    deviceUnloadedSelections.clear();
    deviceUnloadedInfo.clear();
    excludedUnloadedTransferIds.clear();
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

    // Device checkbox handler
    $('.device-checkbox').off('change').on('change', function(e) {
        const groupIndex = parseInt($(this).data('group-index'));
        const deviceIndex = parseInt($(this).data('device-index'));
        const isChecked = $(this).prop('checked');
        handleDeviceCheckboxChange(groupIndex, deviceIndex, isChecked);
    });

    // Transfer checkbox handler
    $('.transfer-checkbox').off('change').on('change', function(e) {
        const transferId = parseInt($(this).data('transfer-id'));
        const groupIndex = $(this).data('group-index');
        const deviceIndex = $(this).data('device-index');
        const isChecked = $(this).prop('checked');
        handleTransferCheckboxChange(transferId, groupIndex, deviceIndex, isChecked);
    });

    // Unloaded checkbox handler
    $('.unloaded-checkbox').off('change').on('change', function(e) {
        const groupIndex = parseInt($(this).data('group-index'));
        const deviceIndex = parseInt($(this).data('device-index'));
        const isChecked = $(this).prop('checked');
        handleUnloadedCheckboxChange(groupIndex, deviceIndex, isChecked);
    });
}

/**
 * Handle group checkbox change
 */
function handleGroupCheckboxChange(groupIndex, isChecked) {
    const transferIdsMapOrSet = groupTransferMap.get(groupIndex);

    if (!transferIdsMapOrSet) return;

    if (isChecked) {
        // Count how many transfers are selectable (exist and not cancelled)
        let selectableCount = 0;

        if (transferIdsMapOrSet instanceof Map) {
            transferIdsMapOrSet.forEach((idsSet, type) => {
                idsSet.forEach(id => {
                    const $checkbox = $(`#transfer-${id}`);
                    if ($checkbox.length && !$checkbox.prop('disabled')) {
                        selectableCount++;
                    }
                });
            });
        } else {
            // Legacy Set format
            transferIdsMapOrSet.forEach(id => {
                const $checkbox = $(`#transfer-${id}`);
                if ($checkbox.length && !$checkbox.prop('disabled')) {
                    selectableCount++;
                }
            });
        }

        // If there's at least one selectable transfer, proceed
        if (selectableCount > 0) {
            // Select all child transfers that are not cancelled
            if (transferIdsMapOrSet instanceof Map) {
                transferIdsMapOrSet.forEach((idsSet, type) => {
                    idsSet.forEach(id => {
                        const $checkbox = $(`#transfer-${id}`);
                        if ($checkbox.length && !$checkbox.prop('disabled')) {
                            // Add to selectedTransferIds Map
                            if (!selectedTransferIds.has(type)) {
                                selectedTransferIds.set(type, new Set());
                            }
                            selectedTransferIds.get(type).add(id);
                            $checkbox.prop('checked', true);
                        }
                    });
                });
            } else {
                // Legacy Set format
                transferIdsMapOrSet.forEach(id => {
                    const $checkbox = $(`#transfer-${id}`);
                    if ($checkbox.length && !$checkbox.prop('disabled')) {
                        const $row = $(`tr[data-row-id="${id}"]`);
                        const deviceType = $row.attr('data-device-type');

                        // Add to selectedTransferIds Map
                        if (!selectedTransferIds.has(deviceType)) {
                            selectedTransferIds.set(deviceType, new Set());
                        }
                        selectedTransferIds.get(deviceType).add(id);
                        $checkbox.prop('checked', true);
                    }
                });
            }
            selectedGroupIndexes.add(groupIndex);

            // Also check all device checkboxes in this group
            deviceTransferMap.forEach((transfers, deviceKey) => {
                if (deviceKey.startsWith(`${groupIndex}-`)) {
                    const [, deviceIndex] = deviceKey.split('-');
                    $(`#device-${groupIndex}-${deviceIndex}`).prop('checked', true);
                }
            });

            // Also check all unloaded checkboxes in this group
            deviceUnloadedInfo.forEach((info, deviceKey) => {
                if (deviceKey.startsWith(`${groupIndex}-`)) {
                    const [, deviceIndex] = deviceKey.split('-');
                    const $unloadedCheckbox = $(`#unloaded-${groupIndex}-${deviceIndex}`);
                    if ($unloadedCheckbox.length) {
                        $unloadedCheckbox.prop('checked', true);
                        deviceUnloadedSelections.set(deviceKey, true);
                    }
                }
            });
        } else {
            // Cannot select group - uncheck it (all transfers are cancelled)
            $(`#group-${groupIndex}`).prop('checked', false);
        }
    } else {
        // Deselect all child transfers
        if (transferIdsMapOrSet instanceof Map) {
            transferIdsMapOrSet.forEach((idsSet, type) => {
                idsSet.forEach(id => {
                    const deviceTypeSet = selectedTransferIds.get(type);
                    if (deviceTypeSet) {
                        deviceTypeSet.delete(id);
                        if (deviceTypeSet.size === 0) {
                            selectedTransferIds.delete(type);
                        }
                    }
                    $(`#transfer-${id}`).prop('checked', false);
                });
            });
        } else {
            // Legacy Set format
            transferIdsMapOrSet.forEach(id => {
                const $row = $(`tr[data-row-id="${id}"]`);
                const deviceType = $row.attr('data-device-type');

                const deviceTypeSet = selectedTransferIds.get(deviceType);
                if (deviceTypeSet) {
                    deviceTypeSet.delete(id);
                    if (deviceTypeSet.size === 0) {
                        selectedTransferIds.delete(deviceType);
                    }
                }
                $(`#transfer-${id}`).prop('checked', false);
            });
        }
        selectedGroupIndexes.delete(groupIndex);

        // Also uncheck all device checkboxes in this group
        deviceTransferMap.forEach((transfers, deviceKey) => {
            if (deviceKey.startsWith(`${groupIndex}-`)) {
                const [, deviceIndex] = deviceKey.split('-');
                $(`#device-${groupIndex}-${deviceIndex}`).prop('checked', false);
            }
        });

        // Also uncheck all unloaded checkboxes in this group
        deviceUnloadedInfo.forEach((info, deviceKey) => {
            if (deviceKey.startsWith(`${groupIndex}-`)) {
                const [, deviceIndex] = deviceKey.split('-');
                const $unloadedCheckbox = $(`#unloaded-${groupIndex}-${deviceIndex}`);
                if ($unloadedCheckbox.length) {
                    $unloadedCheckbox.prop('checked', false);
                    deviceUnloadedSelections.delete(deviceKey);
                }
            }
        });
    }

    updateCancelButtonVisibility();
}

/**
 * Handle device checkbox change
 */
function handleDeviceCheckboxChange(groupIndex, deviceIndex, isChecked) {
    const deviceKey = `${groupIndex}-${deviceIndex}`;
    const transferIds = deviceTransferMap.get(deviceKey);

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

        // Check if there are active unloaded items (checkbox only exists if there are active unloaded)
        const $unloadedCheckbox = $(`#unloaded-${groupIndex}-${deviceIndex}`);
        const hasActiveUnloadedItems = $unloadedCheckbox.length > 0;

        // If there's at least one selectable transfer OR active unloaded items, proceed
        if (selectableCount > 0 || hasActiveUnloadedItems) {
            // Select all child transfers that are not cancelled
            transferIds.forEach(id => {
                const $checkbox = $(`#transfer-${id}`);
                if ($checkbox.length && !$checkbox.prop('disabled')) {
                    // Get device type from row
                    const $row = $(`tr[data-row-id="${id}"]`);
                    const deviceType = $row.attr('data-device-type');

                    // Add to selectedTransferIds Map
                    if (!selectedTransferIds.has(deviceType)) {
                        selectedTransferIds.set(deviceType, new Set());
                    }
                    selectedTransferIds.get(deviceType).add(id);
                    $checkbox.prop('checked', true);
                }
            });

            // Check unloaded checkbox if it exists (and has active items)
            if (hasActiveUnloadedItems) {
                $unloadedCheckbox.prop('checked', true);
                deviceUnloadedSelections.set(deviceKey, true);
            }

            // Check if all devices in the group are now selected - if so, check the group checkbox
            checkGroupCheckboxState(groupIndex);
        } else {
            // All loaded transfers are cancelled AND no active unloaded items
            // Uncheck the device checkbox - nothing to select
            $(`#device-${groupIndex}-${deviceIndex}`).prop('checked', false);
        }
    } else {
        // Deselect all child transfers
        transferIds.forEach(id => {
            const $row = $(`tr[data-row-id="${id}"]`);
            const deviceType = $row.attr('data-device-type');

            // Remove from selectedTransferIds Map
            const deviceTypeSet = selectedTransferIds.get(deviceType);
            if (deviceTypeSet) {
                deviceTypeSet.delete(id);
                if (deviceTypeSet.size === 0) {
                    selectedTransferIds.delete(deviceType);
                }
            }
            $(`#transfer-${id}`).prop('checked', false);
        });

        // Uncheck unloaded checkbox
        const $unloadedCheckbox = $(`#unloaded-${groupIndex}-${deviceIndex}`);
        if ($unloadedCheckbox.length) {
            $unloadedCheckbox.prop('checked', false);
            deviceUnloadedSelections.delete(deviceKey);
        }

        // Uncheck the group checkbox
        selectedGroupIndexes.delete(groupIndex);
        $(`#group-${groupIndex}`).prop('checked', false);
    }

    updateCancelButtonVisibility();
}

/**
 * Handle unloaded checkbox change
 */
function handleUnloadedCheckboxChange(groupIndex, deviceIndex, isChecked) {
    const deviceKey = `${groupIndex}-${deviceIndex}`;

    if (isChecked) {
        deviceUnloadedSelections.set(deviceKey, true);
    } else {
        deviceUnloadedSelections.delete(deviceKey);
    }

    updateCancelButtonVisibility();
}

/**
 * Check and update group checkbox state based on all devices
 */
function checkGroupCheckboxState(groupIndex) {
    const groupTransfersMap = groupTransferMap.get(parseInt(groupIndex));
    if (!groupTransfersMap) return;

    let allSelected = true;

    // Check if groupTransfersMap is a Map (grouped by device type) or a Set (legacy)
    if (groupTransfersMap instanceof Map) {
        groupTransfersMap.forEach((idsSet, type) => {
            idsSet.forEach(id => {
                const $checkbox = $(`#transfer-${id}`);
                const deviceTypeSet = selectedTransferIds.get(type);
                if (!$checkbox.prop('disabled') && (!deviceTypeSet || !deviceTypeSet.has(id))) {
                    allSelected = false;
                }
            });
        });
    } else {
        // Legacy Set format - iterate directly over IDs
        groupTransfersMap.forEach(id => {
            const $checkbox = $(`#transfer-${id}`);
            const $row = $(`tr[data-row-id="${id}"]`);
            const deviceType = $row.attr('data-device-type');
            const deviceTypeSet = selectedTransferIds.get(deviceType);
            if (!$checkbox.prop('disabled') && (!deviceTypeSet || !deviceTypeSet.has(id))) {
                allSelected = false;
            }
        });
    }

    if (allSelected) {
        selectedGroupIndexes.add(parseInt(groupIndex));
        $(`#group-${groupIndex}`).prop('checked', true);
    }
}

/**
 * Handle transfer checkbox change
 */
function handleTransferCheckboxChange(transferId, groupIndex, deviceIndex, isChecked) {
    if (isChecked) {
        // Don't allow selection of cancelled transfers
        const $row = $(`tr[data-row-id="${transferId}"]`);
        if ($row.attr('data-is-cancelled') === '1') {
            $(`#transfer-${transferId}`).prop('checked', false);
            return;
        }

        // Get device type from row
        const deviceType = $row.attr('data-device-type');

        // Add to selectedTransferIds Map
        if (!selectedTransferIds.has(deviceType)) {
            selectedTransferIds.set(deviceType, new Set());
        }
        selectedTransferIds.get(deviceType).add(transferId);

        // Check if this transfer belongs to a device
        if (deviceIndex !== undefined && deviceIndex !== null && deviceIndex !== '') {
            const deviceKey = `${groupIndex}-${deviceIndex}`;
            const deviceTransfers = deviceTransferMap.get(deviceKey);

            if (deviceTransfers) {
                // Check if ALL non-cancelled transfers in device are now selected
                let allDeviceSelected = true;
                deviceTransfers.forEach(id => {
                    const $checkbox = $(`#transfer-${id}`);
                    const $checkboxRow = $(`tr[data-row-id="${id}"]`);
                    const checkboxDeviceType = $checkboxRow.attr('data-device-type');
                    const deviceTypeSet = selectedTransferIds.get(checkboxDeviceType);
                    if (!$checkbox.prop('disabled') && (!deviceTypeSet || !deviceTypeSet.has(id))) {
                        allDeviceSelected = false;
                    }
                });

                if (allDeviceSelected) {
                    $(`#device-${groupIndex}-${deviceIndex}`).prop('checked', true);
                }
            }
        }

        // Check if this transfer belongs to a group
        if (groupIndex !== undefined && groupIndex !== null && groupIndex !== '') {
            const groupTransfers = groupTransferMap.get(parseInt(groupIndex));

            if (groupTransfers) {
                // Check if ALL non-cancelled siblings are now selected
                let allSelected = true;

                // Check if groupTransfers is a Map or Set
                if (groupTransfers instanceof Map) {
                    groupTransfers.forEach((idsSet, type) => {
                        idsSet.forEach(id => {
                            const $checkbox = $(`#transfer-${id}`);
                            const deviceTypeSet = selectedTransferIds.get(type);
                            // Skip cancelled transfers
                            if (!$checkbox.prop('disabled') && (!deviceTypeSet || !deviceTypeSet.has(id))) {
                                allSelected = false;
                            }
                        });
                    });
                } else {
                    // Legacy Set format
                    groupTransfers.forEach(id => {
                        const $checkbox = $(`#transfer-${id}`);
                        const $checkboxRow = $(`tr[data-row-id="${id}"]`);
                        const checkboxDeviceType = $checkboxRow.attr('data-device-type');
                        const deviceTypeSet = selectedTransferIds.get(checkboxDeviceType);
                        // Skip cancelled transfers
                        if (!$checkbox.prop('disabled') && (!deviceTypeSet || !deviceTypeSet.has(id))) {
                            allSelected = false;
                        }
                    });
                }

                if (allSelected) {
                    // Check the group checkbox
                    selectedGroupIndexes.add(parseInt(groupIndex));
                    $(`#group-${groupIndex}`).prop('checked', true);
                }
            }
        }
    } else {
        // Get device type from row
        const $row = $(`tr[data-row-id="${transferId}"]`);
        const deviceType = $row.attr('data-device-type');

        // Remove from selectedTransferIds Map
        const deviceTypeSet = selectedTransferIds.get(deviceType);
        if (deviceTypeSet) {
            deviceTypeSet.delete(transferId);
            if (deviceTypeSet.size === 0) {
                selectedTransferIds.delete(deviceType);
            }
        }

        // If this transfer belongs to a device, uncheck the device
        if (deviceIndex !== undefined && deviceIndex !== null && deviceIndex !== '') {
            $(`#device-${groupIndex}-${deviceIndex}`).prop('checked', false);
        }

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
    // Count all selected transfer IDs across all device types
    let totalCount = 0;
    selectedTransferIds.forEach((idsSet, deviceType) => {
        totalCount += idsSet.size;
    });

    // Add count of unloaded rows from checked unloaded checkboxes
    deviceUnloadedSelections.forEach((isSelected, deviceKey) => {
        if (isSelected) {
            // Get the unloaded info to count only active (non-cancelled) unloaded rows
            const unloadedInfo = deviceUnloadedInfo.get(deviceKey);
            if (unloadedInfo) {
                // Only count active unloaded rows, not cancelled ones
                const activeUnloadedCount = unloadedInfo.unloadedActiveCount || 0;
                totalCount += activeUnloadedCount;
            }
        }
    });

    const $cancelBtn = $('#cancelSelectedBtn');

    if (totalCount > 0) {
        $cancelBtn.show();
        $cancelBtn.find('.badge').text(totalCount);
    } else {
        $cancelBtn.hide();
    }
}

/**
 * Handle cancel selected transfers
 */
function handleCancelSelected() {
    // Count all selected transfer IDs across all device types
    let loadedSelectedCount = 0;
    selectedTransferIds.forEach((idsSet, deviceType) => {
        loadedSelectedCount += idsSet.size;
    });

    // Count unloaded selections
    let unloadedSelectedCount = 0;
    deviceUnloadedSelections.forEach((isSelected, deviceKey) => {
        if (isSelected) {
            unloadedSelectedCount++;
        }
    });

    const totalSelectedCount = loadedSelectedCount + unloadedSelectedCount;

    if (totalSelectedCount === 0) {
        showErrorMessage('Nie zaznaczono żadnych transferów do anulowania');
        return;
    }

    // Populate the modal with transfer details
    populateCancelModal();

    // Show the modal
    $('#cancelTransfersModal').modal('show');
}

/**
 * Fetch just the IDs of unloaded rows for a specific device
 * Returns a Promise that resolves with array of IDs
 * @param {Object} unloadedDevice - Device info (group_id, device_id, device_type)
 * @param {boolean} includeCancelled - Whether to include cancelled transfers (default: false)
 */
function fetchUnloadedTransferIds(unloadedDevice, includeCancelled = false) {
    return new Promise((resolve, reject) => {
        const groupId = unloadedDevice.group_id;
        const deviceId = unloadedDevice.device_id;
        const deviceType = unloadedDevice.device_type;
        const allIds = [];
        let offset = 0;
        const limit = 100; // Fetch larger batches for efficiency (IDs only, no joins)

        // Get already loaded transfer IDs to exclude
        const loadedIds = Array.from(selectedTransferIds);

        function fetchPage() {
            $.ajax({
                type: 'POST',
                url: COMPONENTS_PATH + '/archive/archive-get-unloaded-rows.php',
                data: {
                    group_id: groupId,
                    device_id: deviceId,
                    device_type: deviceType,
                    offset: offset,
                    limit: limit,
                    excluded_loaded_ids: JSON.stringify(loadedIds),
                    include_cancelled: includeCancelled ? '1' : '0'
                },
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        reject(new Error(response.message || 'Failed to fetch unloaded IDs'));
                        return;
                    }

                    // Collect just the IDs from entries
                    const ids = response.entries.map(entry => entry.id);
                    allIds.push(...ids);

                    // If there are more, fetch next page
                    if (response.hasMore) {
                        offset += response.loaded;
                        fetchPage(); // Recursive call for next page
                    } else {
                        // All IDs fetched
                        resolve(allIds);
                    }
                },
                error: function(xhr, status, error) {
                    reject(new Error('AJAX error: ' + error));
                }
            });
        }

        fetchPage(); // Start fetching
    });
}

/**
 * Group transfers by device
 * Returns Map: deviceKey -> {deviceInfo, transfers[]}
 */
function groupTransfersByDevice(transfers) {
    const deviceGroups = new Map();

    // Process all transfers
    transfers.forEach(transfer => {
        const deviceKey = `${transfer.device_type || 'unknown'}-${transfer.device_id || 'unknown'}`;

        if (!deviceGroups.has(deviceKey)) {
            deviceGroups.set(deviceKey, {
                deviceId: transfer.device_id,
                deviceType: transfer.device_type,
                deviceName: transfer.device_name,
                transfers: [],
                totalQty: 0,
                cancelledCount: 0
            });
        }

        const group = deviceGroups.get(deviceKey);
        group.transfers.push(transfer);
        group.totalQty += parseFloat(transfer.qty || 0);

        // Track cancelled transfers count
        if (transfer.is_cancelled == 1) {
            group.cancelledCount++;
        }
    });

    return deviceGroups;
}

/**
 * Render device groups in modal
 */
function renderDeviceGroups(deviceGroups, $summaryBody) {
    let deviceIndex = 0;

    deviceGroups.forEach((groupData, deviceKey) => {
        // Count only active (non-cancelled) transfers for display
        const activeTransferCount = groupData.transfers.filter(t => t.is_cancelled != 1).length;
        const qtyText = groupData.totalQty > 0 ? `+${groupData.totalQty.toFixed(2)}` : groupData.totalQty.toFixed(2);
        const deviceTypeBadge = getDeviceTypeBadge(groupData.deviceType);

        // Cancelled count badge (only show if > 0)
        const cancelledBadge = groupData.cancelledCount > 0
            ? `<span class="badge badge-warning ml-2">${groupData.cancelledCount} anulowanych</span>`
            : '';

        // Device header row (collapsible)
        const headerRow = `
            <tr class="modal-device-group-header"
                data-device-key="${deviceKey}"
                data-device-index="${deviceIndex}"
                style="cursor: pointer; background-color: #e9ecef; font-weight: 500;">
                <td colspan="7">
                    <input type="checkbox" class="device-select-checkbox mr-2" data-device-key="${deviceKey}" checked style="cursor: pointer;">
                    <i class="bi bi-chevron-right toggle-icon-device-modal" style="transition: transform 0.2s;"></i>
                    ${deviceTypeBadge}
                    <strong>${escapeHtml(groupData.deviceName)}</strong>
                    <span class="badge badge-secondary ml-2">${activeTransferCount} wierszy</span>
                    ${cancelledBadge}
                    <span class="text-muted ml-2 device-quantity-display">Łącznie: ${qtyText}</span>
                </td>
            </tr>
        `;
        $summaryBody.append(headerRow);

        // Device content (collapsible, initially hidden)
        const contentStart = `
            <tr class="modal-device-group-content collapse"
                data-device-key="${deviceKey}">
                <td colspan="7" class="p-0">
                    <table class="table table-sm mb-0" style="background-color: #fff;">
                        <tbody>
        `;
        $summaryBody.append(contentStart);

        // Render all transfers for this device
        groupData.transfers.forEach(transfer => {
            const userName = `${transfer.user_name || ''} ${transfer.user_surname || ''}`.trim() || '-';
            const transferTypeBadge = getDeviceTypeBadge(transfer.device_type);
            const transferId = transfer.id;
            const checkboxId = `modal-transfer-${transferId}`;
            const isCancelled = transfer.is_cancelled == 1;
            const cancelledClass = isCancelled ? 'cancelled-row' : '';
            const cancelledBadge = isCancelled ? '<span class="badge badge-danger badge-sm ml-1">Anulowany</span>' : '';

            // Cancelled transfers: display without checkbox (informational only)
            // Active transfers: display with checkbox (selectable for cancellation)
            const checkboxHtml = isCancelled
                ? ''
                : `<div class="custom-control custom-checkbox">
                       <input type="checkbox" class="custom-control-input modal-transfer-checkbox"
                              id="${checkboxId}"
                              data-transfer-id="${transferId}"
                              data-device-type="${transfer.device_type}"
                              data-device-key="${deviceKey}"
                              checked>
                       <label class="custom-control-label" for="${checkboxId}"></label>
                   </div>`;

            const rowHtml = `
                <tr class="modal-device-transfer-row collapse modal-device-group-content ${cancelledClass}"
                    data-device-key="${deviceKey}"
                    data-transfer-id="${transferId}"
                    data-is-cancelled="${isCancelled ? '1' : '0'}">
                    <td style="width: 30px; padding-left: 30px;">
                        ${checkboxHtml}
                    </td>
                    <td style="padding-left: 10px;">${escapeHtml(userName)}</td>
                    <td>${escapeHtml(transfer.sub_magazine_name)}</td>
                    <td>${transferTypeBadge}${escapeHtml(transfer.device_name)}${cancelledBadge}</td>
                    <td>${escapeHtml(transfer.input_type_name || '')}</td>
                    <td>${transfer.qty > 0 ? '+' : ''}${parseFloat(transfer.qty).toFixed(2)}</td>
                    <td>${formatDateTime(transfer.timestamp)}</td>
                </tr>
            `;
            $summaryBody.append(rowHtml);
        });

        // Close content table
        const contentEnd = `
                        </tbody>
                    </table>
                </td>
            </tr>
        `;
        $summaryBody.append(contentEnd);

        deviceIndex++;
    });
}

/**
 * Render transfers hierarchically: grouped by transfer group, then by device
 */
function renderHierarchicalGroups(allTransfers, $summaryBody) {
    // First, group transfers by transfer_group_id
    const transfersByGroup = new Map();

    allTransfers.forEach(transfer => {
        const groupId = transfer.transfer_group_id || 'no_group';
        if (!transfersByGroup.has(groupId)) {
            transfersByGroup.set(groupId, []);
        }
        transfersByGroup.get(groupId).push(transfer);
    });

    let groupIndex = 0;

    // Render each transfer group
    transfersByGroup.forEach((transfers, groupId) => {
        // Calculate group totals
        let groupTotalQty = 0;
        const activeTransferCount = transfers.filter(t => t.is_cancelled != 1).length;
        const cancelledTransferCount = transfers.filter(t => t.is_cancelled == 1).length;
        let userName = '';
        let groupDate = '';

        transfers.forEach(transfer => {
            groupTotalQty += parseFloat(transfer.qty || 0);
            if (!userName && transfer.user_name) {
                userName = `${transfer.user_name || ''} ${transfer.user_surname || ''}`.trim();
            }
            if (!groupDate && transfer.timestamp) {
                groupDate = transfer.timestamp;
            }
        });

        const qtyText = groupTotalQty > 0 ? `+${groupTotalQty.toFixed(2)}` : groupTotalQty.toFixed(2);
        const groupKey = `group-${groupId}`;

        // Cancelled count badge (only show if > 0)
        const cancelledBadge = cancelledTransferCount > 0
            ? `<span class="badge badge-warning ml-2">${cancelledTransferCount} anulowanych</span>`
            : '';

        // Transfer group header row
        const groupHeaderRow = `
            <tr class="modal-transfer-group-header"
                data-group-key="${groupKey}"
                data-group-id="${groupId}"
                style="cursor: pointer; background-color: #d1ecf1; font-weight: 600; border-top: 2px solid #0c5460;">
                <td colspan="7">
                    <input type="checkbox" class="group-select-checkbox mr-2" data-group-key="${groupKey}" checked style="cursor: pointer;">
                    <i class="bi bi-chevron-right toggle-icon-group-modal" style="transition: transform 0.2s;"></i>
                    <i class="bi bi-folder2"></i>
                    <strong>Grupa transferów #${groupId}</strong>
                    <span class="badge badge-info ml-2">${activeTransferCount} transferów</span>
                    ${cancelledBadge}
                    <span class="text-muted ml-2">Użytkownik: ${escapeHtml(userName)}</span>
                    <span class="text-muted ml-2 group-quantity-display">Łącznie: ${qtyText}</span>
                    <span class="text-muted ml-2">${formatDateTime(groupDate)}</span>
                </td>
            </tr>
        `;
        $summaryBody.append(groupHeaderRow);

        // Group content container (collapsible)
        const groupContentStart = `
            <tr class="modal-transfer-group-content collapse"
                data-group-key="${groupKey}">
                <td colspan="7" class="p-0">
                    <table class="table table-sm mb-0" style="background-color: #f8f9fa;">
                        <tbody>
        `;
        $summaryBody.append(groupContentStart);

        // Now group transfers within this group by device
        const deviceGroups = groupTransfersByDevice(transfers);

        // Render devices within this transfer group
        let deviceIndex = 0;
        deviceGroups.forEach((deviceData, deviceKey) => {
            // Count only active (non-cancelled) transfers for display
            const activeTransferCount = deviceData.transfers.filter(t => t.is_cancelled != 1).length;
            const deviceQtyText = deviceData.totalQty > 0 ? `+${deviceData.totalQty.toFixed(2)}` : deviceData.totalQty.toFixed(2);
            const deviceTypeBadge = getDeviceTypeBadge(deviceData.deviceType);
            const fullDeviceKey = `${groupKey}-${deviceKey}`;

            // Cancelled count badge (only show if > 0)
            const cancelledBadge = deviceData.cancelledCount > 0
                ? `<span class="badge badge-warning ml-2">${deviceData.cancelledCount} anulowanych</span>`
                : '';

            // Device header row (nested under group)
            const deviceHeaderRow = `
                <tr class="modal-device-group-header collapse modal-transfer-group-content"
                    data-group-key="${groupKey}"
                    data-device-key="${fullDeviceKey}"
                    data-device-index="${deviceIndex}"
                    style="cursor: pointer; background-color: #e9ecef; font-weight: 500;">
                    <td colspan="7" style="padding-left: 30px;">
                        <input type="checkbox" class="device-select-checkbox mr-2" data-device-key="${fullDeviceKey}" checked style="cursor: pointer;">
                        <i class="bi bi-chevron-right toggle-icon-device-modal" style="transition: transform 0.2s;"></i>
                        ${deviceTypeBadge}
                        <strong>${escapeHtml(deviceData.deviceName)}</strong>
                        <span class="badge badge-secondary ml-2">${activeTransferCount} wierszy</span>
                        ${cancelledBadge}
                        <span class="text-muted ml-2 device-quantity-display">Łącznie: ${deviceQtyText}</span>
                    </td>
                </tr>
            `;
            $summaryBody.append(deviceHeaderRow);

            // Device content (nested table)
            const deviceContentStart = `
                <tr class="modal-device-group-content collapse"
                    data-device-key="${fullDeviceKey}">
                    <td colspan="7" class="p-0">
                        <table class="table table-sm mb-0" style="background-color: #fff;">
                            <tbody>
            `;
            $summaryBody.append(deviceContentStart);

            // Render individual transfers
            deviceData.transfers.forEach(transfer => {
                const transferUserName = `${transfer.user_name || ''} ${transfer.user_surname || ''}`.trim() || '-';
                const transferTypeBadge = getDeviceTypeBadge(transfer.device_type);
                const transferId = transfer.id;
                const checkboxId = `modal-transfer-${transferId}`;
                const isCancelled = transfer.is_cancelled == 1;
                const cancelledClass = isCancelled ? 'cancelled-row' : '';
                const cancelledBadge = isCancelled ? '<span class="badge badge-danger badge-sm ml-1">Anulowany</span>' : '';

                // Cancelled transfers: display without checkbox (informational only)
                // Active transfers: display with checkbox (selectable for cancellation)
                const checkboxHtml = isCancelled
                    ? ''
                    : `<div class="custom-control custom-checkbox">
                           <input type="checkbox" class="custom-control-input modal-transfer-checkbox"
                                  id="${checkboxId}"
                                  data-transfer-id="${transferId}"
                                  data-device-type="${transfer.device_type}"
                                  data-device-key="${fullDeviceKey}"
                                  checked>
                           <label class="custom-control-label" for="${checkboxId}"></label>
                       </div>`;

                const transferRowHtml = `
                    <tr class="modal-device-transfer-row collapse modal-device-group-content ${cancelledClass}"
                        data-device-key="${fullDeviceKey}"
                        data-transfer-id="${transferId}"
                        data-is-cancelled="${isCancelled ? '1' : '0'}">
                        <td style="width: 30px; padding-left: 60px;">
                            ${checkboxHtml}
                        </td>
                        <td style="padding-left: 10px;">${escapeHtml(transferUserName)}</td>
                        <td>${escapeHtml(transfer.sub_magazine_name)}</td>
                        <td>${transferTypeBadge}${escapeHtml(transfer.device_name)}${cancelledBadge}</td>
                        <td>${escapeHtml(transfer.input_type_name || '')}</td>
                        <td>${transfer.qty > 0 ? '+' : ''}${parseFloat(transfer.qty).toFixed(2)}</td>
                        <td>${formatDateTime(transfer.timestamp)}</td>
                    </tr>
                `;
                $summaryBody.append(transferRowHtml);
            });

            // Close device content table
            const deviceContentEnd = `
                            </tbody>
                        </table>
                    </td>
                </tr>
            `;
            $summaryBody.append(deviceContentEnd);

            deviceIndex++;
        });

        // Close group content table
        const groupContentEnd = `
                        </tbody>
                    </table>
                </td>
            </tr>
        `;
        $summaryBody.append(groupContentEnd);

        groupIndex++;
    });
}

/**
 * Populate cancel modal with transfer details
 */
function populateCancelModal() {
    const $summaryBody = $('#cancelSummaryBody');
    const $confirmBtn = $('#confirmCancelTransfers');

    // Reset warnings
    $('#groupCancellationWarning').hide();
    $('#incompleteGroupWarning').hide();

    // Show loading state
    $summaryBody.html('<tr><td colspan="7" class="text-center py-5"><div class="spinner-border" role="status"><span class="sr-only">Ładowanie...</span></div><p class="mt-3">Ładowanie wszystkich wierszy...</p></td></tr>');
    $confirmBtn.prop('disabled', true);

    // Check if user selected any transfer groups
    const selectedGroups = Array.from(selectedGroupIndexes);
    const hasGroupSelection = selectedGroups.length > 0;

    // Store rendering mode for later use (e.g., when loading missing transfers)
    window.currentModalIsHierarchical = hasGroupSelection;

    // Collect unloaded device selections
    const unloadedDevices = [];
    deviceUnloadedSelections.forEach((isSelected, deviceKey) => {
        if (isSelected) {
            const info = deviceUnloadedInfo.get(deviceKey);
            if (info) {
                unloadedDevices.push({
                    group_id: info.groupId,
                    device_id: info.deviceId,
                    device_type: info.deviceType
                });
            }
        }
    });

    // Fetch unloaded IDs in parallel (if any)
    // Include cancelled transfers since we're in cancellation modal context
    const fetchPromises = unloadedDevices.map(device => fetchUnloadedTransferIds(device, true));

    Promise.all(fetchPromises)
        .then(unloadedIdArrays => {
            // Combine all IDs by device type (loaded + unloaded)
            const idsByDeviceType = new Map();

            // Add loaded IDs grouped by device type
            selectedTransferIds.forEach((idsSet, deviceType) => {
                if (!idsByDeviceType.has(deviceType)) {
                    idsByDeviceType.set(deviceType, new Set());
                }
                idsSet.forEach(id => idsByDeviceType.get(deviceType).add(id));
            });

            // Add unloaded IDs grouped by device type
            unloadedDevices.forEach((device, index) => {
                const ids = unloadedIdArrays[index];
                const deviceType = device.device_type;
                if (!idsByDeviceType.has(deviceType)) {
                    idsByDeviceType.set(deviceType, new Set());
                }
                ids.forEach(id => idsByDeviceType.get(deviceType).add(id));
            });

            // Store for later use
            window.currentModalIdsByType = idsByDeviceType;

            // If groups are selected, validate completeness
            if (hasGroupSelection) {
                return validateGroupSelections(selectedGroups, idsByDeviceType);
            } else {
                return { isComplete: true, idsByDeviceType: idsByDeviceType };
            }
        })
        .then(validationResult => {
            const idsByDeviceType = validationResult.idsByDeviceType || window.currentModalIdsByType;

            // For complete group cancellations, automatically include ALL transfers from the group (including cancelled ones)
            if (validationResult.groupValidations) {
                validationResult.groupValidations.forEach(validation => {
                    if (validation.is_complete) {
                        // Use all_transfers_by_type_including_cancelled to fetch cancelled transfers too
                        const transfersToFetch = validation.all_transfers_by_type_including_cancelled || validation.all_transfers_by_type;
                        if (transfersToFetch) {
                            Object.keys(transfersToFetch).forEach(deviceType => {
                                const allIdsForType = transfersToFetch[deviceType];
                                if (!idsByDeviceType.has(deviceType)) {
                                    idsByDeviceType.set(deviceType, new Set());
                                }
                                allIdsForType.forEach(id => idsByDeviceType.get(deviceType).add(id));
                            });
                        }
                    }
                });
            }

            // Show group warnings if applicable
            if (validationResult.groupValidations) {
                displayGroupValidationWarnings(validationResult.groupValidations);
            }

            // Fetch transfers for each device type separately
            const fetchTransfersPromises = [];
            idsByDeviceType.forEach((idsSet, deviceType) => {
                const idsArray = Array.from(idsSet);
                fetchTransfersPromises.push(
                    $.ajax({
                        type: 'POST',
                        url: COMPONENTS_PATH + '/archive/archive-get-transfers-by-ids.php',
                        data: {
                            transfer_ids: JSON.stringify(idsArray),
                            device_type: deviceType
                        },
                        dataType: 'json'
                    })
                );
            });

            return Promise.all(fetchTransfersPromises);
        })
        .then(responses => {
            // Combine all transfers from all device types
            const allTransfers = [];
            let totalCount = 0;

            responses.forEach(response => {
                if (!response.success) {
                    throw new Error(response.message || 'Failed to fetch transfers');
                }
                allTransfers.push(...response.transfers);
                totalCount += response.count;
            });

            // Store for later validation
            window.currentModalTransfers = allTransfers;

            // Calculate count of non-cancelled transfers only
            const nonCancelledCount = allTransfers.filter(t => !t.is_cancelled || t.is_cancelled == 0).length;

            // Update total count (only non-cancelled)
            $('#cancelCount').text(nonCancelledCount);

            // Clear loading message
            $summaryBody.empty();

            // Render transfers - use hierarchical view if groups selected
            if (hasGroupSelection) {
                // Hierarchical: Transfer Groups > Devices > Transfers
                renderHierarchicalGroups(allTransfers, $summaryBody);
            } else {
                // Flat: Devices > Transfers
                const deviceGroups = groupTransfersByDevice(allTransfers);
                renderDeviceGroups(deviceGroups, $summaryBody);
            }

            // Attach event handlers
            attachModalEventHandlers();

            // Enable confirm button
            $confirmBtn.prop('disabled', false);
        })
        .catch(error => {
            console.error('Error fetching transfers:', error);
            $summaryBody.html(`<tr><td colspan="7" class="text-center text-danger py-3">Błąd podczas ładowania wierszy: ${error.message}</td></tr>`);
            $confirmBtn.prop('disabled', false);
        });
}

/**
 * Validate if selected transfer groups are complete
 */
function validateGroupSelections(selectedGroups, idsByDeviceType) {
    // Get transfer group IDs from DOM
    const groupIds = selectedGroups.map(groupIndex => {
        const $groupRow = $(`.group-row[data-group-index="${groupIndex}"]`);
        return $groupRow.data('group-id');
    }).filter(id => id); // Filter out null/undefined

    if (groupIds.length === 0) {
        return Promise.resolve({ isComplete: true, idsByDeviceType: idsByDeviceType });
    }

    // Convert Map to plain object for JSON serialization
    const idsByTypeObj = {};
    idsByDeviceType.forEach((idsSet, deviceType) => {
        idsByTypeObj[deviceType] = Array.from(idsSet);
    });

    // Validate each group
    const validationPromises = groupIds.map(groupId => {
        return $.ajax({
            type: 'POST',
            url: COMPONENTS_PATH + '/archive/archive-validate-group-selection.php',
            data: {
                transfer_group_id: groupId,
                selected_transfer_ids_by_type: JSON.stringify(idsByTypeObj)
            },
            dataType: 'json'
        });
    });

    return Promise.all(validationPromises)
        .then(responses => {
            const groupValidations = responses.map(response => {
                if (!response.success) {
                    throw new Error(response.message || 'Validation failed');
                }
                return response;
            });

            // Store for later use in cancellation
            window.groupValidationData = groupValidations;

            return {
                isComplete: groupValidations.every(v => v.is_complete),
                idsByDeviceType: idsByDeviceType,
                groupValidations: groupValidations
            };
        });
}

/**
 * Display group validation warnings in modal
 */
function displayGroupValidationWarnings(groupValidations) {
    const $groupWarning = $('#groupCancellationWarning');
    const $incompleteWarning = $('#incompleteGroupWarning');
    const $manualUncheckedWarning = $('#manualUncheckedWarning');
    const $groupDetails = $('#groupCancellationDetails');
    const $incompleteMessage = $('#incompleteGroupMessage');
    const $manualUncheckedMessage = $('#manualUncheckedMessage');

    // Hide all warnings initially
    $groupWarning.hide();
    $incompleteWarning.hide();
    $manualUncheckedWarning.hide();

    let hasComplete = false;
    let hasIncompleteNotLoaded = false;
    let hasManuallyUnchecked = false;
    let completeGroupsHtml = '';
    let incompleteDetails = '';
    let manualUncheckedDetails = '';

    groupValidations.forEach(validation => {
        if (validation.is_complete) {
            // Case 1: Complete group - will be fully cancelled
            // Missing transfers (including cancelled ones) are automatically loaded
            hasComplete = true;
            const includesCancelled = validation.missing_count > 0;
            const cancelledNote = includesCancelled ? ' (w tym anulowane transfery)' : '';
            completeGroupsHtml += `<p class="mb-1">✓ Grupa transferów #${validation.group_id} zostanie całkowicie anulowana (${validation.total_count} transferów${cancelledNote})</p>`;
        } else {
            // Case 2: Incomplete group - some transfers not selected
            // For now, we show this as an informational warning
            hasIncompleteNotLoaded = true;
            incompleteDetails += `<p>Grupa transferów #${validation.group_id}: wybrano ${validation.selected_count} z ${validation.total_count} transferów (brakuje ${validation.missing_count})</p>`;
        }
    });

    // Show complete groups warning
    if (hasComplete) {
        $groupDetails.html(completeGroupsHtml);
        $groupWarning.show();
    }

    // Show incomplete (not loaded) warning with load button
    if (hasIncompleteNotLoaded) {
        $incompleteMessage.html(incompleteDetails);
        $incompleteWarning.show();

        // Store missing transfers for load button (only for incomplete groups)
        window.missingTransfersByType = {};
        groupValidations.forEach(validation => {
            if (!validation.is_complete && validation.missing_transfers_by_type) {
                Object.keys(validation.missing_transfers_by_type).forEach(deviceType => {
                    if (!window.missingTransfersByType[deviceType]) {
                        window.missingTransfersByType[deviceType] = [];
                    }
                    window.missingTransfersByType[deviceType].push(...validation.missing_transfers_by_type[deviceType]);
                });
            }
        });
    }
}

/**
 * Load missing transfers into modal
 */
function loadMissingTransfers() {
    if (!window.missingTransfersByType) {
        return;
    }

    const $loadBtn = $('#loadMissingTransfers');
    $loadBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2"></span>Ładowanie...');

    // Fetch missing transfers
    const fetchPromises = [];
    Object.keys(window.missingTransfersByType).forEach(deviceType => {
        const ids = window.missingTransfersByType[deviceType];
        fetchPromises.push(
            $.ajax({
                type: 'POST',
                url: COMPONENTS_PATH + '/archive/archive-get-transfers-by-ids.php',
                data: {
                    transfer_ids: JSON.stringify(ids),
                    device_type: deviceType
                },
                dataType: 'json'
            })
        );
    });

    Promise.all(fetchPromises)
        .then(responses => {
            // Combine all new transfers
            const newTransfers = [];
            responses.forEach(response => {
                if (response.success) {
                    newTransfers.push(...response.transfers);
                }
            });

            // Add to current modal transfers
            if (window.currentModalTransfers) {
                window.currentModalTransfers.push(...newTransfers);
            }

            // Re-render modal with all transfers using appropriate structure
            const $summaryBody = $('#cancelSummaryBody');
            $summaryBody.empty();

            if (window.currentModalIsHierarchical) {
                // Hierarchical: Transfer Groups > Devices > Transfers
                renderHierarchicalGroups(window.currentModalTransfers, $summaryBody);
            } else {
                // Flat: Devices > Transfers
                const allDeviceGroups = groupTransfersByDevice(window.currentModalTransfers);
                renderDeviceGroups(allDeviceGroups, $summaryBody);
            }

            // Update count (only non-cancelled)
            const nonCancelledCount = window.currentModalTransfers.filter(t => !t.is_cancelled || t.is_cancelled == 0).length;
            $('#cancelCount').text(nonCancelledCount);

            // Update warning - now complete
            $('#incompleteGroupWarning').hide();

            // Re-validate to update group status
            if (window.groupValidationData) {
                const updatedValidations = window.groupValidationData.map(v => {
                    return { ...v, is_complete: true };
                });
                window.groupValidationData = updatedValidations;
                displayGroupValidationWarnings(updatedValidations);
            }

            // Re-attach handlers
            attachModalEventHandlers();

            $loadBtn.prop('disabled', false).html('<i class="bi bi-download"></i> Załaduj brakujące transfery');
        })
        .catch(error => {
            console.error('Error loading missing transfers:', error);
            showErrorMessage('Błąd podczas ładowania brakujących transferów');
            $loadBtn.prop('disabled', false).html('<i class="bi bi-download"></i> Załaduj brakujące transfery');
        });
}

/**
 * Revalidate group completeness based on currently checked transfers in modal
 */
function revalidateGroupCompleteness() {
    if (!window.groupValidationData || window.groupValidationData.length === 0) {
        return;
    }

    // Collect currently checked transfer IDs from modal, grouped by device type
    const currentCheckedByType = {};
    $('.modal-transfer-checkbox:checked').each(function() {
        const transferId = parseInt($(this).data('transfer-id'));
        const deviceType = $(this).data('device-type');

        if (!currentCheckedByType[deviceType]) {
            currentCheckedByType[deviceType] = [];
        }
        currentCheckedByType[deviceType].push(transferId);
    });

    // Get all transfer IDs currently in the modal
    const modalTransferIds = new Set();
    if (window.currentModalTransfers) {
        window.currentModalTransfers.forEach(transfer => {
            modalTransferIds.add(transfer.id);
        });
    }

    // Update validation data based on current selections
    const updatedValidations = window.groupValidationData.map(validation => {
        let selectedCount = 0;
        let isComplete = true;
        let allTransfersInModal = true;

        // Check each device type in this group
        Object.keys(validation.all_transfers_by_type).forEach(deviceType => {
            const allIds = validation.all_transfers_by_type[deviceType];
            const checkedIds = currentCheckedByType[deviceType] || [];

            // Check if all transfers from this group are in the modal
            allIds.forEach(id => {
                if (!modalTransferIds.has(id)) {
                    allTransfersInModal = false;
                }
            });

            // Count how many from this group are checked
            const checkedFromGroup = allIds.filter(id => checkedIds.includes(id));
            selectedCount += checkedFromGroup.length;

            // If not all IDs from this device type are checked, group is incomplete
            if (checkedFromGroup.length < allIds.length) {
                isComplete = false;
            }
        });

        const missingCount = validation.total_count - selectedCount;

        return {
            ...validation,
            selected_count: selectedCount,
            missing_count: missingCount,
            is_complete: isComplete,
            all_transfers_in_modal: allTransfersInModal
        };
    });

    // Update global validation data
    window.groupValidationData = updatedValidations;

    // Re-display warnings with updated data
    displayGroupValidationWarnings(updatedValidations);
}

/**
 * Attach event handlers for cancel modal
 */
function attachModalEventHandlers() {
    // Transfer checkbox handler (unified for all transfers)
    $('.modal-transfer-checkbox').off('change').on('change', function() {
        const $checkbox = $(this);
        const deviceKey = $checkbox.data('device-key');

        // Update parent device checkbox state
        updateDeviceCheckboxState(deviceKey);

        // Update parent group checkbox state if applicable
        const $deviceHeader = $(`.modal-device-group-header[data-device-key="${deviceKey}"]`);
        const groupKey = $deviceHeader.data('group-key');
        if (groupKey) {
            updateGroupCheckboxState(groupKey);
        }

        // Update count badge
        updateModalCount();

        // Revalidate group completeness
        revalidateGroupCompleteness();

        // Update header background colors based on selection completeness
        updateHeaderBackgroundColors();

        // Update dynamic quantities
        updateDynamicQuantities();
    });

    // Transfer group header click handler (collapse/expand)
    $('.modal-transfer-group-header').off('click').on('click', function() {
        const $header = $(this);
        const groupKey = $header.data('group-key');
        const $content = $(`.modal-transfer-group-content[data-group-key="${groupKey}"]`);
        const $icon = $header.find('.toggle-icon-group-modal');

        // Toggle collapse
        if ($content.hasClass('show')) {
            $content.removeClass('show');
            $icon.css('transform', 'rotate(0deg)');

            // Auto-collapse all device rows within this transfer group
            const $deviceHeaders = $(`.modal-device-group-header[data-group-key="${groupKey}"]`);
            $deviceHeaders.each(function() {
                const deviceKey = $(this).data('device-key');
                const $deviceContent = $(`.modal-device-group-content[data-device-key="${deviceKey}"]`);
                const $deviceIcon = $(this).find('.toggle-icon-device-modal');

                // Collapse device content
                $deviceContent.removeClass('show');
                $deviceIcon.css('transform', 'rotate(0deg)');
            });
        } else {
            $content.addClass('show');
            $icon.css('transform', 'rotate(90deg)');
        }
    });

    // Device group header click handler (collapse/expand)
    $('.modal-device-group-header').off('click').on('click', function() {
        const $header = $(this);
        const deviceKey = $header.data('device-key');
        const $content = $(`.modal-device-group-content[data-device-key="${deviceKey}"]`);
        const $icon = $header.find('.toggle-icon-device-modal');

        // Toggle collapse
        if ($content.hasClass('show')) {
            $content.removeClass('show');
            $icon.css('transform', 'rotate(0deg)');
        } else {
            $content.addClass('show');
            $icon.css('transform', 'rotate(90deg)');
        }
    });

    // Load missing transfers button handler
    $('#loadMissingTransfers').off('click').on('click', function() {
        loadMissingTransfers();
    });

    // Transfer group checkbox handler (select/deselect all transfers in group)
    $('.group-select-checkbox').off('change').on('change', function(e) {
        e.stopPropagation(); // Prevent collapse toggle
        const $checkbox = $(this);
        const groupKey = $checkbox.data('group-key');
        const isChecked = $checkbox.is(':checked');

        // Find all device headers within this group
        const $deviceHeaders = $(`.modal-device-group-header[data-group-key="${groupKey}"]`);

        // Toggle all device checkboxes
        $deviceHeaders.each(function() {
            const $deviceCheckbox = $(this).find('.device-select-checkbox');
            $deviceCheckbox.prop('checked', isChecked);

            const deviceKey = $(this).data('device-key');
            // Toggle all transfer checkboxes within this device
            $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`).prop('checked', isChecked);
        });

        // Update all displays
        updateModalCount();
        revalidateGroupCompleteness();
        updateHeaderBackgroundColors();
        updateDynamicQuantities();
    });

    // Device checkbox handler (select/deselect all transfers in device)
    $('.device-select-checkbox').off('change').on('change', function(e) {
        e.stopPropagation(); // Prevent collapse toggle
        const $checkbox = $(this);
        const deviceKey = $checkbox.data('device-key');
        const isChecked = $checkbox.is(':checked');

        // Toggle all transfer checkboxes within this device
        $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`).prop('checked', isChecked);

        // Update parent group checkbox state if applicable
        const $deviceHeader = $checkbox.closest('.modal-device-group-header');
        const groupKey = $deviceHeader.data('group-key');
        if (groupKey) {
            updateGroupCheckboxState(groupKey);
        }

        // Update all displays
        updateModalCount();
        revalidateGroupCompleteness();
        updateHeaderBackgroundColors();
        updateDynamicQuantities();
    });

    // Prevent click event propagation for group checkboxes (prevents collapse/expand when clicking checkbox)
    $('.group-select-checkbox').off('click').on('click', function(e) {
        e.stopPropagation();
    });

    // Prevent click event propagation for device checkboxes (prevents collapse/expand when clicking checkbox)
    $('.device-select-checkbox').off('click').on('click', function(e) {
        e.stopPropagation();
    });
}

/**
 * Update modal count badge based on selections
 */
function updateModalCount() {
    // Count all checked transfer checkboxes in modal
    const count = $('.modal-transfer-checkbox:checked').length;
    $('#cancelCount').text(count);
}

/**
 * Update device checkbox state based on child transfer checkboxes
 */
function updateDeviceCheckboxState(deviceKey) {
    const $deviceCheckbox = $(`.device-select-checkbox[data-device-key="${deviceKey}"]`);
    if ($deviceCheckbox.length === 0) return;

    const $transferCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`);
    const total = $transferCheckboxes.length;
    const checked = $transferCheckboxes.filter(':checked').length;

    // Update device checkbox: checked if all children checked, unchecked otherwise
    $deviceCheckbox.prop('checked', checked === total);
}

/**
 * Update group checkbox state based on child device/transfer checkboxes
 */
function updateGroupCheckboxState(groupKey) {
    const $groupCheckbox = $(`.group-select-checkbox[data-group-key="${groupKey}"]`);
    if ($groupCheckbox.length === 0) return;

    // Find all device headers in this group
    const $deviceHeaders = $(`.modal-device-group-header[data-group-key="${groupKey}"]`);

    let totalCheckboxes = 0;
    let checkedCheckboxes = 0;

    $deviceHeaders.each(function() {
        const deviceKey = $(this).data('device-key');
        const $transferCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`);
        totalCheckboxes += $transferCheckboxes.length;
        checkedCheckboxes += $transferCheckboxes.filter(':checked').length;
    });

    // Update group checkbox: checked if all children checked, unchecked otherwise
    $groupCheckbox.prop('checked', checkedCheckboxes === totalCheckboxes);
}

/**
 * Update header background colors based on checkbox selection completeness
 * Yellow background = some checkboxes unchecked
 * Original background = all checkboxes checked
 */
function updateHeaderBackgroundColors() {
    // Original colors
    const TRANSFER_GROUP_ORIGINAL_COLOR = '#d1ecf1'; // light blue
    const DEVICE_GROUP_ORIGINAL_COLOR = '#e9ecef'; // light gray
    const INCOMPLETE_COLOR = '#fff3cd';

    // Process each transfer group header
    $('.modal-transfer-group-header').each(function() {
        const $groupHeader = $(this);
        const groupKey = $groupHeader.data('group-key');

        // Find all checkboxes in this transfer group by finding all device headers with this group-key
        const $deviceHeaders = $(`.modal-device-group-header[data-group-key="${groupKey}"]`);

        let groupTotalCheckboxes = 0;
        let groupCheckedCheckboxes = 0;

        // Process each device within this transfer group
        $deviceHeaders.each(function() {
            const $deviceHeader = $(this);
            const deviceKey = $deviceHeader.data('device-key');

            // Find all checkboxes for this device
            const $deviceCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`);
            const deviceTotal = $deviceCheckboxes.length;
            const deviceChecked = $deviceCheckboxes.filter(':checked').length;

            // Update device header color
            if (deviceChecked < deviceTotal) {
                $deviceHeader.css('background-color', INCOMPLETE_COLOR);
            } else {
                $deviceHeader.css('background-color', DEVICE_GROUP_ORIGINAL_COLOR);
            }

            // Accumulate for group totals
            groupTotalCheckboxes += deviceTotal;
            groupCheckedCheckboxes += deviceChecked;
        });

        // Update transfer group header color
        if (groupCheckedCheckboxes < groupTotalCheckboxes) {
            $groupHeader.css('background-color', INCOMPLETE_COLOR);
        } else {
            $groupHeader.css('background-color', TRANSFER_GROUP_ORIGINAL_COLOR);
        }
    });

    // Also handle flat view (device groups without transfer groups)
    // Process device headers that don't have a parent transfer group
    $('.modal-device-group-header').each(function() {
        const $deviceHeader = $(this);
        const groupKey = $deviceHeader.data('group-key');

        // Skip if this device belongs to a transfer group (already handled above)
        if (groupKey) {
            return;
        }

        const deviceKey = $deviceHeader.data('device-key');
        const $deviceCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]`);
        const deviceTotal = $deviceCheckboxes.length;
        const deviceChecked = $deviceCheckboxes.filter(':checked').length;

        // Update device header color
        if (deviceChecked < deviceTotal) {
            $deviceHeader.css('background-color', INCOMPLETE_COLOR);
        } else {
            $deviceHeader.css('background-color', DEVICE_GROUP_ORIGINAL_COLOR);
        }
    });

    // Update individual transfer row colors
    $('.modal-transfer-checkbox').each(function() {
        const $checkbox = $(this);
        const $row = $checkbox.closest('tr.modal-device-transfer-row');

        if ($checkbox.is(':checked')) {
            $row.css('background-color', 'white');
        } else {
            $row.css('background-color', INCOMPLETE_COLOR);
        }
    });
}

/**
 * Update dynamic quantities in headers based on checked transfers
 * Recalculates "Łącznie: X" values based on currently selected checkboxes
 */
function updateDynamicQuantities() {
    if (!window.currentModalTransfers) return;

    // Create a map of transfer ID to transfer object for quick lookup
    const transferMap = new Map();
    window.currentModalTransfers.forEach(transfer => {
        transferMap.set(transfer.id, transfer);
    });

    // Update device header quantities (both hierarchical and flat views)
    $('.modal-device-group-header').each(function() {
        const $deviceHeader = $(this);
        const deviceKey = $deviceHeader.data('device-key');

        // Find all CHECKED transfer checkboxes for this device
        const $checkedCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]:checked`);

        // Calculate total quantity from checked transfers
        let totalQty = 0;
        $checkedCheckboxes.each(function() {
            const transferId = parseInt($(this).data('transfer-id'));
            const transfer = transferMap.get(transferId);
            if (transfer && transfer.qty) {
                totalQty += parseFloat(transfer.qty);
            }
        });

        // Format quantity text
        const qtyText = totalQty > 0 ? `+${totalQty.toFixed(2)}` : totalQty.toFixed(2);

        // Update the quantity display span
        const $qtySpan = $deviceHeader.find('.device-quantity-display');
        $qtySpan.text(`Łącznie: ${qtyText}`);
    });

    // Update transfer group header quantities (hierarchical view only)
    $('.modal-transfer-group-header').each(function() {
        const $groupHeader = $(this);
        const groupKey = $groupHeader.data('group-key');

        // Find all device headers within this group
        const $deviceHeaders = $(`.modal-device-group-header[data-group-key="${groupKey}"]`);

        // Calculate total quantity across all devices in this group
        let groupTotalQty = 0;
        $deviceHeaders.each(function() {
            const deviceKey = $(this).data('device-key');
            const $checkedCheckboxes = $(`.modal-transfer-checkbox[data-device-key="${deviceKey}"]:checked`);

            $checkedCheckboxes.each(function() {
                const transferId = parseInt($(this).data('transfer-id'));
                const transfer = transferMap.get(transferId);
                if (transfer && transfer.qty) {
                    groupTotalQty += parseFloat(transfer.qty);
                }
            });
        });

        // Format quantity text
        const qtyText = groupTotalQty > 0 ? `+${groupTotalQty.toFixed(2)}` : groupTotalQty.toFixed(2);

        // Update the quantity display span
        const $qtySpan = $groupHeader.find('.group-quantity-display');
        $qtySpan.text(`Łącznie: ${qtyText}`);
    });
}

/**
 * Cancel selected transfers via AJAX
 */
function cancelSelectedTransfers() {
    // Collect all checked transfer IDs from modal, grouped by device type
    const transferIdsByType = {};

    $('.modal-transfer-checkbox:checked').each(function() {
        const transferId = parseInt($(this).data('transfer-id'));
        const deviceType = $(this).data('device-type');

        if (!transferIdsByType[deviceType]) {
            transferIdsByType[deviceType] = [];
        }
        transferIdsByType[deviceType].push(transferId);
    });

    // Check if this is complete group cancellation
    const cancelGroups = [];
    if (window.groupValidationData) {
        window.groupValidationData.forEach(validation => {
            if (validation.is_complete) {
                cancelGroups.push(validation.group_id);
            }
        });
    }

    // Show loading state
    const $confirmBtn = $('#confirmCancelTransfers');
    const originalHtml = $confirmBtn.html();
    $confirmBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2"></span>Anulowanie...');

    $.ajax({
        type: 'POST',
        url: COMPONENTS_PATH + '/archive/cancel-transfer.php',
        data: {
            action: 'cancel_transfers',
            transfer_ids_by_type: JSON.stringify(transferIdsByType),
            cancel_groups: JSON.stringify(cancelGroups)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showSuccessMessage(response.message || 'Transfery zostały pomyślnie anulowane');

                // Close modal
                $('#cancelTransfersModal').modal('hide');

                // Clear selections
                clearSelections();
                updateCancelButtonVisibility();

                // Reload archive table
                loadArchive();
            } else {
                showErrorMessage('Błąd: ' + (response.message || 'Nie udało się anulować transferów'));
            }

            // Reset button state
            $confirmBtn.prop('disabled', false).html(originalHtml);
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.log('Response text:', xhr.responseText);
            showErrorMessage('Błąd podczas anulowania transferów');

            // Reset button state
            $confirmBtn.prop('disabled', false).html(originalHtml);
        }
    });
}
