/**
 * archive-view.js
 * Handles archive view with transfer group rendering and filtering
 */

// State management
let currentPage = 1;
let totalCount = null;
let hasNextPage = false;
let itemsPerPage = 20;
let isLoading = false;
let currentSnapshotTs = null;

// Selection tracking
let selectedTransferIds = new Map(); // Manual level 3 selections: deviceType -> Set of IDs
let selectedGroupIds = new Set();    // Symbolic level 1 selections
let selectedDeviceKeys = new Set();  // Symbolic level 2 selections (Format: "groupId:deviceId:deviceType")

// In-memory data for loaded items (for UI sync)
let groupTransferMap = new Map();    // groupIndex -> Map(deviceType -> Set of IDs)
let deviceTransferMap = new Map();   // "groupIndex-deviceIndex" -> Set of IDs

$(document).ready(function() {
    console.log("Archive View initialized");
    $('.selectpicker').selectpicker();
    $('#noGrouping, #quickNoGrouping').prop('checked', false);

    const oneMonthAgo = new Date();
    oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);
    $('#dateFrom').val(oneMonthAgo.toISOString().split('T')[0]);

    const urlParams = new URLSearchParams(window.location.search);
    const flowpinSessionParam = urlParams.get('flowpin_session');
    if (flowpinSessionParam) {
        $('#flowpinSession').val(flowpinSessionParam).selectpicker('refresh');
    }

    setTimeout(() => { 
        if ($('#quickDeviceType').val() || $('#deviceType').val()) {
            loadArchive(); 
        }
    }, 500);

    attachEventHandlers();
});

function attachEventHandlers() {
    $("#quickDeviceType").change(function() {
        const val = $(this).val();
        $("#deviceType").val(val).selectpicker('refresh');
        handleDeviceTypeChange(val);
    });

    $("#deviceType").change(function() {
        const val = $(this).val();
        $("#quickDeviceType").val(val);
        handleDeviceTypeChange(val);
    });

    $("#list__device, #magazine, #user, #input_type, #flowpinSession, #dateFrom, #dateTo").on('change', function() {
        resetToFirstPage();
        loadArchive();
    });

    $("#refreshArchive").click(() => { resetToFirstPage(); loadArchive(); });

    $("#quickShowCancelled, #showCancelled").change(function() {
        const isChecked = $(this).prop('checked');
        $("#quickShowCancelled, #showCancelled").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    $("#quickNoGrouping, #noGrouping").change(function() {
        const isChecked = $(this).prop('checked');
        $("#quickNoGrouping, #noGrouping").prop('checked', isChecked);
        resetToFirstPage();
        loadArchive();
    });

    $("#clearDevice").click(() => clearDeviceFilter());
    $("#clearMagazineUser").click(() => clearMagazineUserFilter());
    $("#clearInputType").click(() => clearInputTypeFilter());
    $("#clearDates").click(() => clearDatesFilter());
    $("#clearSessionFilter").click(() => { $("#flowpinSession").val('').selectpicker('refresh'); resetToFirstPage(); loadArchive(); });

    $("#cancelSelectedBtn").click(() => handleCancelSelected(false));
    $("#confirmCancelTransfers").click(() => cancelSelectedTransfers());
}

function handleDeviceTypeChange(type) {
    $("#list__device").empty();
    if (!type) { 
        $("#list__device").prop("disabled", true).selectpicker('refresh'); 
        clearTable(); 
        return; 
    }
    if (type === 'all') { 
        $("#list__device").prop("disabled", true).selectpicker('refresh'); 
    } else { 
        const $source = $('#list__' + type);
        if ($source.length) {
            $source.find('option').clone().appendTo('#list__device');
        }
        $('#list__device').prop("disabled", false).selectpicker('refresh'); 
    }
    resetToFirstPage();
    loadArchive();
}

function clearDeviceFilter() { $('#deviceType, #quickDeviceType').val(''); $("#list__device").empty().prop('disabled', true).selectpicker('refresh'); resetToFirstPage(); clearTable(); }
function clearMagazineUserFilter() { $('#magazine, #user').val([]).selectpicker('refresh'); resetToFirstPage(); loadArchive(); }
function clearInputTypeFilter() { $('#input_type').val([]).selectpicker('refresh'); resetToFirstPage(); loadArchive(); }
function clearDatesFilter() { $('#dateFrom, #dateTo').val(''); resetToFirstPage(); loadArchive(); }
function resetToFirstPage() { currentPage = 1; totalCount = null; currentSnapshotTs = null; }

function loadArchive() {
    if (isLoading) return;
    const type = $("#quickDeviceType").val() || $("#deviceType").val();
    if (!type) { clearTable(); return; }

    isLoading = true;
    $("#transferSpinner").show();

    const filters = {
        device_type: type,
        device_ids: $("#list__device").val() || [],
        user_ids: $("#user").val() || [],
        magazine_ids: $("#magazine").val() || [],
        input_type_id: $("#input_type").val() || [],
        flowpin_session_id: $("#flowpinSession").val() || null,
        date_from: $("#dateFrom").val() || null,
        date_to: $("#dateTo").val() || null,
        show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0',
        no_grouping: $("#quickNoGrouping").is(':checked') ? '1' : '0',
        page: currentPage,
        snapshot_ts: currentSnapshotTs
    };

    const path = (typeof COMPONENTS_PATH !== 'undefined') ? COMPONENTS_PATH : '/atte_ms_new/public_html/components';

    $.ajax({
        type: "POST",
        url: path + "/archive/archive-table.php",
        data: filters,
        dataType: "json",
        success: function(res) {
            currentSnapshotTs = res.snapshot_ts;
            renderArchiveTable(res);
            if (totalCount === null) loadTotalCount(filters);
            const loadedLength = res.entries ? res.entries.length : (res.groups ? res.groups.length : 0);
            hasNextPage = totalCount !== null ? (totalCount > currentPage * itemsPerPage) : (loadedLength >= itemsPerPage);
            renderPagination();
            isLoading = false;
            $("#transferSpinner").hide();
        },
        error: (xhr, status, error) => { 
            console.error("AJAX Error:", error);
            showErrorMessage("Błąd ładowania danych"); 
            isLoading = false; 
            $("#transferSpinner").hide(); 
        }
    });
}

function loadTotalCount(filters) {
    const path = (typeof COMPONENTS_PATH !== 'undefined') ? COMPONENTS_PATH : '/atte_ms_new/public_html/components';
    $.ajax({
        type: "POST",
        url: path + "/archive/archive-table.php",
        data: {...filters, mode: 'count'},
        dataType: "json",
        success: (res) => { 
            totalCount = res.totalCount; 
            hasNextPage = totalCount > currentPage * itemsPerPage; 
            renderPagination(); 
        }
    });
}

function renderArchiveTable(res) {
    const $tbody = $("#archiveTableBody").empty();
    clearSelectionsState();
    
    if (res.entries) {
        if (res.entries.length === 0) { $tbody.append(`<tr><td colspan="8" class="text-center text-muted">Brak danych</td></tr>`); return; }
        res.entries.forEach(entry => renderSingleRow(entry));
    } else if (res.groups) {
        if (res.groups.length === 0) { $tbody.append(`<tr><td colspan="8" class="text-center text-muted">Brak danych</td></tr>`); return; }
        res.groups.forEach((group, gIdx) => {
            const userName = `${group.user_name || ''} ${group.user_surname || ''}`.trim();
            renderGroupHeader(group, gIdx, userName);
            if (group.devices) {
                group.devices.forEach((dev, dIdx) => {
                    renderDeviceRow(dev, gIdx, dIdx, group.group_id);
                    $tbody.append(`<tr class="device-child-${gIdx}-${dIdx} device-child-group-${gIdx} d-none l3-placeholder" 
                        id="l3-placeholder-${gIdx}-${dIdx}" 
                        data-loaded="0" 
                        data-group-id="${group.group_id}" 
                        data-device-id="${dev.device_id}" 
                        data-device-type="${dev.device_type}">
                        <td colspan="8" class="text-center py-2">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <small class="ml-2">Ładowanie...</small>
                        </td></tr>`);
                });
            }
        });
    }

    attachCollapseHandlers();
    attachCheckboxHandlers();
    updateCancelButtonVisibility();
}

function renderSingleRow(entry) {
    const isCnl = entry.is_cancelled == 1;
    const typeBadge = getDeviceTypeBadge(entry.device_type);
    const row = `
        <tr class="${isCnl ? 'cancelled-row' : ''}" data-row-id="${entry.id}" data-device-type="${entry.device_type}" data-is-cancelled="${isCnl ? '1' : '0'}">
            <td class="text-center"><div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input transfer-checkbox" 
                    id="transfer-${entry.id}" data-transfer-id="${entry.id}" 
                    data-device-type="${entry.device_type}" ${isCnl ? 'disabled' : ''}>
                <label class="custom-control-label" for="transfer-${entry.id}"></label></div></td>
            <td>${escapeHtml(`${entry.user_name || ''} ${entry.user_surname || ''}`.trim() || '-')}</td>
            <td>${escapeHtml(entry.sub_magazine_name)}</td>
            <td>${typeBadge}${escapeHtml(entry.device_name)}</td>
            <td>${escapeHtml(entry.input_type_name || '')}</td>
            <td>${entry.qty > 0 ? '+' : ''}${parseFloat(entry.qty).toFixed(2)}</td>
            <td>${formatDateTime(entry.timestamp)}</td>
            <td><small>${escapeHtml(entry.comment || '')}</small></td>
        </tr>
    `;
    $("#archiveTableBody").append(row);
}

function renderGroupHeader(group, gIdx, user) {
    const isCnl = group.all_cancelled;
    const cnlBadge = group.has_cancelled && !isCnl ? `<span class="badge badge-warning ml-1">${group.cancelled_count} anulowanych</span>` : '';
    const row = `
        <tr class="group-row ${isCnl ? 'cancelled-group' : ''}" style="cursor:pointer;" 
            data-group-index="${gIdx}" 
            data-group-id="${group.group_id}"
            data-total-count="${group.entries_count}"
            aria-expanded="false">
            <td class="text-center d-flex">
                <div class="custom-control custom-checkbox d-inline-block" onclick="event.stopPropagation();">
                    <input type="checkbox" class="custom-control-input group-checkbox" 
                        id="group-${gIdx}" data-group-index="${gIdx}" 
                        data-group-id="${group.group_id}" ${isCnl ? 'disabled' : ''}>
                    <label class="custom-control-label" for="group-${gIdx}"></label>
                </div>
                <i class="bi bi-chevron-right toggle-icon ml-1"></i>
            </td>
            <td>${escapeHtml(user || '-')}</td>
            <td colspan="3">${group.group_notes ? `<strong>${escapeHtml(group.group_notes)}</strong><br>` : ''}<small class="text-muted">Grupa transferowa #${group.group_id || gIdx}</small>${cnlBadge}</td>
            <td><span class="badge badge-secondary">${group.entries_count} wpisów</span></td>
            <td>${formatDateTime(group.group_created_at)}</td>
            <td></td>
        </tr>
    `;
    $("#archiveTableBody").append(row);
}

function renderDeviceRow(dev, gIdx, dIdx, groupId) {
    const cnlBadge = dev.has_cancelled && !dev.all_cancelled ? `<span class="badge badge-warning ml-1">${dev.total_cancelled_count} anulowanych</span>` : '';
    const deviceKey = `${groupId}:${dev.device_id}:${dev.device_type}`;
    const row = `
        <tr class="device-row group-child-${gIdx} d-none" style="cursor:pointer;" 
            data-group-index="${gIdx}" 
            data-device-index="${dIdx}" 
            data-device-key="${deviceKey}"
            data-total-count="${dev.total_entries_count}"
            aria-expanded="false">
            <td class="text-center indent-cell" onclick="event.stopPropagation();">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input device-checkbox" 
                        id="device-${gIdx}-${dIdx}" data-group-index="${gIdx}" 
                        data-device-index="${dIdx}" data-device-key="${deviceKey}" 
                        ${dev.all_cancelled ? 'disabled' : ''}>
                    <label class="custom-control-label" for="device-${gIdx}-${dIdx}"></label>
                </div>
            </td>
            <td class="indent-cell"><i class="bi bi-chevron-right toggle-icon-device"></i></td>
            <td colspan="2"><strong>${escapeHtml(dev.device_name)}</strong><span class="badge badge-light ml-1">${dev.total_entries_count} wpisów</span>${cnlBadge}</td>
            <td></td>
            <td>${getDeviceTypeBadge(dev.device_type)}<strong>${dev.total_qty > 0 ? '+' : ''}${parseFloat(dev.total_qty).toFixed(2)}</strong></td>
            <td></td>
            <td></td>
        </tr>
    `;
    $("#archiveTableBody").append(row);
}

function attachCollapseHandlers() {
    $('.group-row').off('click').on('click', function() {
        const $this = $(this);
        const gIdx = $this.data('group-index');
        const isExp = $this.attr('aria-expanded') === 'true';
        $this.attr('aria-expanded', !isExp);
        $this.find('.toggle-icon').toggleClass('bi-chevron-down', !isExp).toggleClass('bi-chevron-right', isExp);
        const $children = $(`.group-child-${gIdx}`);
        if (isExp) {
            $children.addClass('d-none').attr('aria-expanded', 'false');
            $(`.device-child-group-${gIdx}`).addClass('d-none');
            $children.find('.toggle-icon-device').removeClass('bi-chevron-down').addClass('bi-chevron-right');
        } else {
            $children.removeClass('d-none');
        }
    });

    $('.device-row').off('click').on('click', function() {
        const $this = $(this);
        const gIdx = $this.data('group-index'), dIdx = $this.data('device-index');
        const isExp = $this.attr('aria-expanded') === 'true';
        $this.attr('aria-expanded', !isExp);
        $this.find('.toggle-icon-device').toggleClass('bi-chevron-down', !isExp).toggleClass('bi-chevron-right', isExp);
        const $children = $(`.device-child-${gIdx}-${dIdx}`);
        if (isExp) { $children.addClass('d-none'); } 
        else {
            $children.removeClass('d-none');
            const $p = $(`#l3-placeholder-${gIdx}-${dIdx}`);
            if ($p.length && $p.data('loaded') == '0') {
                loadDeviceEntries($p.data('group-id'), gIdx, $p.data('device-id'), $p.data('device-type'), dIdx, 0);
            }
        }
    });
}

function loadDeviceEntries(groupId, gIdx, dId, dType, dIdx, offset) {
    const $p = $(`#l3-placeholder-${gIdx}-${dIdx}`);
    const path = (typeof COMPONENTS_PATH !== 'undefined') ? COMPONENTS_PATH : '/atte_ms_new/public_html/components';
    $.ajax({
        type: "POST",
        url: path + "/archive/archive-load-group-entries.php",
        data: { transfer_group_id: groupId, device_type: 'all', device_id: dId, device_type_filter: dType, offset: offset, limit: 50, show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0' },
        dataType: "json",
        success: function(res) {
            if (!res.success) return;
            const uiKey = `${gIdx}-${dIdx}`;
            const groupIdInt = parseInt(groupId);
            
            res.entries.forEach(e => {
                const isCnl = e.is_cancelled == 1;
                const row = `<tr class="device-child-${gIdx}-${dIdx} device-child-group-${gIdx} ${isCnl ? 'cancelled-row' : ''} detail-row-level-2" data-group-index="${gIdx}" data-device-index="${dIdx}" data-row-id="${e.id}" data-device-type="${e.device_type}"><td class="text-center indent-cell-2"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input transfer-checkbox" id="transfer-${e.id}" data-transfer-id="${e.id}" data-device-type="${e.device_type}" data-group-index="${gIdx}" data-device-index="${dIdx}" ${isCnl ? 'disabled' : ''}><label class="custom-control-label" for="transfer-${e.id}"></label></div></td><td class="indent-cell-2">${escapeHtml(`${e.user_name || ''} ${e.user_surname || ''}`.trim() || '-')}</td><td>${escapeHtml(e.sub_magazine_name)}</td><td>${getDeviceTypeBadge(e.device_type)}${escapeHtml(e.device_name)}</td><td>${escapeHtml(e.input_type_name || '')}</td><td>${e.qty > 0 ? '+' : ''}${parseFloat(e.qty).toFixed(2)}</td><td>${formatDateTime(e.timestamp)}</td><td><small>${escapeHtml(e.comment || '')}</small></td></tr>`;
                
                // Track in-memory for UI sync
                if (!deviceTransferMap.has(uiKey)) deviceTransferMap.set(uiKey, new Set());
                deviceTransferMap.get(uiKey).add(e.id);
                
                if (!groupTransferMap.has(gIdx)) groupTransferMap.set(gIdx, new Map());
                const gMap = groupTransferMap.get(gIdx);
                if (!gMap.has(e.device_type)) gMap.set(e.device_type, new Set());
                gMap.get(e.device_type).add(e.id);

                $p.before(row);

                // If parent group or device is already selected, select this new row
                if (selectedGroupIds.has(groupIdInt) || selectedDeviceKeys.has(`${groupId}:${dId}:${dType}`)) {
                    if (!selectedTransferIds.has(e.device_type)) selectedTransferIds.set(e.device_type, new Set());
                    selectedTransferIds.get(e.device_type).add(e.id);
                    $(`#transfer-${e.id}`).prop('checked', true);
                }
            });
            if (res.hasMore) {
                $p.html(`<td colspan="8" class="text-center py-2"><button class="btn btn-sm btn-link load-device-entries" data-group-id="${groupId}" data-group-index="${gIdx}" data-device-id="${dId}" data-device-type="${dType}" data-device-index="${dIdx}" data-offset="${offset + res.loaded}">Załaduj więcej (${res.remaining} pozostało)</button></td>`).data('loaded', '1');
            } else $p.remove();
            attachCheckboxHandlers();
        }
    });
}

function attachCheckboxHandlers() {
    $('.group-checkbox').off('change').on('change', function() { handleGroupCheckboxChange($(this)); });
    $('.device-checkbox').off('change').on('change', function() { handleDeviceCheckboxChange($(this)); });
    $('.transfer-checkbox').off('change').on('change', function() { handleTransferCheckboxChange($(this)); });
}

function handleGroupCheckboxChange($cb) {
    const gIdx = parseInt($cb.data('group-index'));
    const gId = parseInt($cb.data('group-id'));
    const isChecked = $cb.prop('checked');

    if (isChecked) selectedGroupIds.add(gId);
    else selectedGroupIds.delete(gId);

    // Sync child devices (visually and in selection sets)
    $(`.device-checkbox[data-group-index="${gIdx}"]`).each(function() {
        const $devCb = $(this);
        $devCb.prop('checked', isChecked);
        const devKey = $devCb.data('device-key');
        if (isChecked) selectedDeviceKeys.add(devKey);
        else selectedDeviceKeys.delete(devKey);
    });

    // Sync loaded level 3 rows
    const gMap = groupTransferMap.get(gIdx);
    if (gMap) {
        gMap.forEach((ids, type) => {
            ids.forEach(id => {
                const $rowCb = $(`#transfer-${id}`);
                if ($rowCb.length && !$rowCb.prop('disabled')) {
                    $rowCb.prop('checked', isChecked);
                    if (!selectedTransferIds.has(type)) selectedTransferIds.set(type, new Set());
                    isChecked ? selectedTransferIds.get(type).add(id) : selectedTransferIds.get(type).delete(id);
                }
            });
        });
    }
    updateCancelButtonVisibility();
}

function handleDeviceCheckboxChange($cb) {
    const gIdx = parseInt($cb.data('group-index'));
    const dIdx = parseInt($cb.data('device-index'));
    const devKey = $cb.data('device-key');
    const isChecked = $cb.prop('checked');

    if (isChecked) selectedDeviceKeys.add(devKey);
    else {
        selectedDeviceKeys.delete(devKey);
        // If device is unchecked, the group cannot be fully checked
        const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
        selectedGroupIds.delete(gId);
        $(`#group-${gIdx}`).prop('checked', false);
    }

    // Sync loaded level 3 rows
    const uiKey = `${gIdx}-${dIdx}`;
    const ids = deviceTransferMap.get(uiKey);
    if (ids) {
        ids.forEach(id => {
            const $rowCb = $(`#transfer-${id}`);
            if ($rowCb.length && !$rowCb.prop('disabled')) {
                $rowCb.prop('checked', isChecked);
                const type = $rowCb.data('device-type');
                if (!selectedTransferIds.has(type)) selectedTransferIds.set(type, new Set());
                isChecked ? selectedTransferIds.get(type).add(id) : selectedTransferIds.get(type).delete(id);
            }
        });
    }
    
    // Check if all devices in group are now selected
    if (isChecked) {
        let allChecked = true;
        $(`.device-checkbox[data-group-index="${gIdx}"]`).each(function() { if (!$(this).prop('checked')) allChecked = false; });
        if (allChecked) {
            const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
            selectedGroupIds.add(gId);
            $(`#group-${gIdx}`).prop('checked', true);
        }
    }

    updateCancelButtonVisibility();
}

function handleTransferCheckboxChange($cb) {
    const id = parseInt($cb.data('transfer-id'));
    const type = $cb.data('device-type');
    const gIdx = $cb.data('group-index');
    const dIdx = $cb.data('device-index');
    const isChecked = $cb.prop('checked');

    if (!selectedTransferIds.has(type)) selectedTransferIds.set(type, new Set());
    isChecked ? selectedTransferIds.get(type).add(id) : selectedTransferIds.get(type).delete(id);

    if (!isChecked) {
        // Drop symbolic parents
        const devKey = $(`.device-row[data-group-index="${gIdx}"][data-device-index="${dIdx}"]`).data('device-key');
        selectedDeviceKeys.delete(devKey);
        $(`#device-${gIdx}-${dIdx}`).prop('checked', false);
        
        const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
        selectedGroupIds.delete(gId);
        $(`#group-${gIdx}`).prop('checked', false);
    } else {
        // Upgrade to symbolic parents if all loaded are checked
        const uiKey = `${gIdx}-${dIdx}`;
        const loadedIds = deviceTransferMap.get(uiKey);
        if (loadedIds) {
            let allDevChecked = true;
            loadedIds.forEach(tid => { if (!$(`#transfer-${tid}`).prop('checked') && !$(`#transfer-${tid}`).prop('disabled')) allDevChecked = false; });
            if (allDevChecked) {
                const devKey = $(`.device-row[data-group-index="${gIdx}"][data-device-index="${dIdx}"]`).data('device-key');
                selectedDeviceKeys.add(devKey);
                $(`#device-${gIdx}-${dIdx}`).prop('checked', true);
                
                let allGroupChecked = true;
                $(`.device-checkbox[data-group-index="${gIdx}"]`).each(function() { if (!$(this).prop('checked')) allGroupChecked = false; });
                if (allGroupChecked) {
                    const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
                    selectedGroupIds.add(gId);
                    $(`#group-${gIdx}`).prop('checked', true);
                }
            }
        }
    }

    updateCancelButtonVisibility();
}

function updateCancelButtonVisibility() {
    let total = 0;
    const processedGroupIds = new Set();
    const processedDeviceKeys = new Set();

    // 1. Count from selected groups
    selectedGroupIds.forEach(id => {
        const $row = $(`.group-row[data-group-id="${id}"]`);
        if ($row.length) {
            total += parseInt($row.data('total-count') || 0);
            processedGroupIds.add(id);
        }
    });

    // 2. Count from selected devices (if group not selected)
    selectedDeviceKeys.forEach(key => {
        const [gId, dId, type] = key.split(':');
        if (!processedGroupIds.has(parseInt(gId))) {
            const $row = $(`.device-row[data-device-key="${key}"]`);
            if ($row.length) {
                total += parseInt($row.data('total-count') || 0);
                processedDeviceKeys.add(key);
            }
        }
    });

    // 3. Count from individual rows (if device/group not selected)
    selectedTransferIds.forEach((set, type) => {
        set.forEach(id => {
            const $row = $(`tr[data-row-id="${id}"]`);
            const gIdx = $row.data('group-index');
            const dIdx = $row.data('device-index');
            const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
            const devKey = $(`.device-row[data-group-index="${gIdx}"][data-device-index="${dIdx}"]`).data('device-key');
            
            if (!processedGroupIds.has(gId) && !processedDeviceKeys.has(devKey)) {
                total++;
            }
        });
    });

    const $btn = $('#cancelSelectedBtn');
    if (total > 0) $btn.show().find('.badge').text(total);
    else $btn.hide();
}

let resolvedEntriesForCancellation = [];

/**
 * Handle cancellation: Resolve symbolic selections then open modal
 */
function handleCancelSelected(forceAll = false) {
    const path = (typeof COMPONENTS_PATH !== 'undefined') ? COMPONENTS_PATH : '/atte_ms_new/public_html/components';
    const groupIds = Array.from(selectedGroupIds);
    const deviceKeys = Array.from(selectedDeviceKeys).filter(key => {
        const gId = parseInt(key.split(':')[0]);
        return !selectedGroupIds.has(gId);
    });

    const activeFilter = $("#quickDeviceType").val() || $("#deviceType").val() || 'all';

    // Individual rows that are NOT in a selected group or device
    const manualIdsByType = {};
    selectedTransferIds.forEach((set, type) => {
        if (set.size === 0) return;
        set.forEach(id => {
            const $r = $(`tr[data-row-id="${id}"][data-device-type="${type}"]`);
            if ($r.length) {
                const gIdx = $r.data('group-index');
                const dIdx = $r.data('device-index');
                const gId = parseInt($(`.group-row[data-group-index="${gIdx}"]`).data('group-id'));
                const devKey = $(`.device-row[data-group-index="${gIdx}"][data-device-index="${dIdx}"]`).data('device-key');
                
                if (!selectedGroupIds.has(gId) && !selectedDeviceKeys.has(devKey)) {
                    if (!manualIdsByType[type]) manualIdsByType[type] = [];
                    manualIdsByType[type].push(id);
                }
            } else {
                if (!manualIdsByType[type]) manualIdsByType[type] = [];
                manualIdsByType[type].push(id);
            }
        });
    });

    if (!forceAll) {
        $('#cancelTransfersModal').modal('show');
    }
    
    const $body = $('#cancelSummaryBody').html('<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div><br>Analizowanie zaznaczenia...</td></tr>');
    $('#cancelCount').text('...');
    $('#incompleteGroupWarning').hide();
    resolvedEntriesForCancellation = [];

    $.ajax({
        type: "POST",
        url: path + "/archive/archive-resolve-selections.php",
        data: {
            group_ids: groupIds,
            device_keys: deviceKeys,
            manual_ids_by_type: JSON.stringify(manualIdsByType),
            show_cancelled: $("#quickShowCancelled").is(':checked') ? '1' : '0',
            device_type_filter: activeFilter,
            force_all: forceAll ? '1' : '0'
        },
        dataType: "json",
        success: function(res) {
            if (!res.success) { 
                $body.html(`<tr><td colspan="7" class="text-center text-danger">Błąd: ${res.message}</td></tr>`);
                return; 
            }
            
            resolvedEntriesForCancellation = res.entries;
            renderCancellationSummary(res.entries);

            if (res.has_hidden_types) {
                $('#incompleteGroupMessage').html(`Uwaga: Wyświetlono tylko transfery typu <strong>${activeFilter.toUpperCase()}</strong>. Zaznaczone grupy zawierają również inne typy transferów, które są obecnie ukryte.`);
                $('#incompleteGroupWarning').show();
                $('#loadMissingTransfers').off('click').on('click', function() {
                    handleCancelSelected(true);
                });
            }
        },
        error: () => { $body.html('<tr><td colspan="7" class="text-center text-danger">Błąd połączenia z serwerem</td></tr>'); }
    });
}

function cancelSelectedTransfers() {
    if (resolvedEntriesForCancellation.length === 0) return;

    const path = (typeof COMPONENTS_PATH !== 'undefined') ? COMPONENTS_PATH : '/atte_ms_new/public_html/components';
    const idsByType = {};
    resolvedEntriesForCancellation.forEach(e => {
        if (!idsByType[e.device_type]) idsByType[e.device_type] = [];
        idsByType[e.device_type].push(e.id);
    });

    const groupIds = Array.from(selectedGroupIds);

    $("#transferSpinner").show();
    $.ajax({
        type: "POST",
        url: path + "/archive/cancel-transfer.php",
        data: {
            action: 'cancel_transfers',
            transfer_ids_by_type: JSON.stringify(idsByType),
            cancel_groups: JSON.stringify(groupIds)
        },
        dataType: "json",
        success: function(res) {
            $("#transferSpinner").hide();
            if (res.success) {
                showSuccessMessage(res.message);
                loadArchive(); // Reload current page
            } else {
                showErrorMessage(res.message);
            }
        },
        error: () => {
            $("#transferSpinner").hide();
            showErrorMessage("Błąd podczas komunikacji z serwerem");
        }
    });
}

function renderCancellationSummary(entries) {
    const $body = $('#cancelSummaryBody').empty();
    $('#cancelCount').text(entries.length);
    
    if (entries.length === 0) {
        $body.append('<tr><td colspan="7" class="text-center">Brak transferów do anulowania (wszystkie mogą być już anulowane)</td></tr>');
        return;
    }

    // Group by warehouse
    const grouped = {};
    entries.forEach(e => {
        const wh = e.sub_magazine_name || 'Nieokreślony magazyn';
        if (!grouped[wh]) grouped[wh] = [];
        grouped[wh].push(e);
    });

    Object.keys(grouped).sort().forEach((wh, idx) => {
        const groupEntries = grouped[wh];
        const whId = `wh-group-${idx}`;
        // Warehouse header row
        $body.append(`
            <tr class="table-secondary wh-group-header" style="cursor: pointer;" data-target=".${whId}">
                <td colspan="7">
                    <i class="bi bi-chevron-right toggle-icon mr-2"></i>
                    <i class="bi bi-house-door-fill mr-1"></i> 
                    <strong>Magazyn: ${escapeHtml(wh)}</strong> 
                    <span class="badge badge-pill badge-dark ml-2">${groupEntries.length} ${getPolishPlural(groupEntries.length, 'wpis', 'wpisy', 'wpisów')}</span>
                </td>
            </tr>
        `);

        groupEntries.forEach(e => {
            const qty = parseFloat(e.qty);
            const qtyClass = qty > 0 ? 'badge-success' : 'badge-danger';
            const qtySign = qty > 0 ? '+' : '';
            
            const row = `<tr class="${whId} d-none">
                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                <td>${escapeHtml(e.user_name || '')} ${escapeHtml(e.user_surname || '')}</td>
                <td class="text-muted"><small>${escapeHtml(e.sub_magazine_name || '')}</small></td>
                <td>${getDeviceTypeBadge(e.device_type)}${escapeHtml(e.device_name || '')}</td>
                <td>${escapeHtml(e.input_type_name || '')}</td>
                <td><span class="badge ${qtyClass}">${qtySign}${qty.toFixed(2)}</span></td>
                <td><small>${e.timestamp.replace(' ', '<br>')}</small></td>
            </tr>`;
            $body.append(row);
        });
    });

    // Attach click handler for warehouse groups
    $('.wh-group-header').off('click').on('click', function() {
        const target = $(this).data('target');
        const $rows = $(target);
        const isCurrentlyHidden = $rows.hasClass('d-none');
        
        $rows.toggleClass('d-none');
        $(this).find('.toggle-icon')
            .toggleClass('bi-chevron-down', isCurrentlyHidden)
            .toggleClass('bi-chevron-right', !isCurrentlyHidden);
    });
}

function renderPagination() {
    if (totalCount === 0) { $("#paginationTop, #paginationBottom").empty(); return; }
    const isC = totalCount === null, disp = isC ? '...' : totalCount, pages = isC ? 0 : Math.ceil(totalCount / itemsPerPage);
    const html = `<div class="d-flex flex-column align-items-center mb-3">
        <div class="text-muted small mb-2">Wyświetlanie <strong>${(currentPage-1)*itemsPerPage+1}-${isC?currentPage*itemsPerPage:Math.min(currentPage*itemsPerPage, totalCount)}</strong> z <strong>${disp}</strong> elementów</div>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary pagination-btn" data-action="first" ${currentPage===1?'disabled':''}><i class="bi bi-chevron-double-left"></i></button>
            <button class="btn btn-outline-primary pagination-btn" data-action="prev" ${currentPage===1?'disabled':''}><i class="bi bi-chevron-left"></i></button>
            <div class="btn-group">
                <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">${currentPage}</button>
                <div class="dropdown-menu" style="max-height: 300px; overflow-y: auto;">${buildPageDropdownItems(pages)}</div>
            </div>
            <button class="btn btn-outline-primary pagination-btn" data-action="next" ${!hasNextPage?'disabled':''}><i class="bi bi-chevron-right"></i></button>
        </div>
    </div>`;
    $("#paginationTop, #paginationBottom").html(html);
    $('.pagination-btn').click(function() {
        const act = $(this).data('action');
        if (act==='first') currentPage=1; else if (act==='prev' && currentPage>1) currentPage--; else if (act==='next' && hasNextPage) currentPage++;
        loadArchive();
    });
    $('.page-dropdown-item').click(function(e) { e.preventDefault(); currentPage = parseInt($(this).data('page')); loadArchive(); });
}

function buildPageDropdownItems(totalPages) {
    let items = '';
    const pages = totalPages > 0 ? totalPages : Math.max(currentPage + 5, 10);
    for (let i = 1; i <= pages; i++) items += `<a class="dropdown-item page-dropdown-item ${i === currentPage ? 'active' : ''}" href="#" data-page="${i}">${i}</a>`;
    return items;
}

function getPolishPlural(c, s, f, m) { if (c===1) return s; if (c%10>=2 && c%10<=4 && (c%100<10 || c%100>=20)) return f; return m; }
function formatDateTime(ts) { return ts ? ts.replace(' ', '<br><small>') + '</small>' : ''; }
function escapeHtml(t) { if (!t) return ''; const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}; return t.toString().replace(/[&<>"']/g, m => map[m]); }
function getDeviceTypeBadge(t) { if (!t) return ''; const cls = {'sku': 'badge-primary', 'tht': 'badge-success', 'smd': 'badge-info', 'parts': 'badge-warning'}; return `<span class="badge ${cls[t.toLowerCase()] || 'badge-secondary'} mr-1">${t.toUpperCase()}</span>`; }
function showErrorMessage(m) { $("#ajaxResult").append(`<div class="alert alert-danger alert-dismissible fade show">${m}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>`); setTimeout(() => $(".alert-danger").alert('close'), 5000); }
function clearTable() { $("#archiveTableBody").html(`<tr><td colspan="8" class="text-center text-muted">Wybierz typ urządzenia aby wyświetlić historię transferów</td></tr>`); $("#paginationTop, #paginationBottom").html(''); }

function clearSelectionsState() { 
    selectedTransferIds.clear(); 
    selectedGroupIds.clear();
    selectedDeviceKeys.clear();
    groupTransferMap.clear(); 
    deviceTransferMap.clear(); 
}
