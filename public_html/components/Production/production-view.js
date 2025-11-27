// Get device type from script tag URL parameter
const urlParams = new URLSearchParams(document.currentScript.src.split('?')[1]);
const DEVICE_TYPE = urlParams.get('deviceType') || 'smd';
const IS_SMD = DEVICE_TYPE === 'smd';

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

function generateLastProduction(deviceId, transferGroupId){
    let data = {deviceType: DEVICE_TYPE, deviceId: deviceId};
    if (transferGroupId) {
        data.transferGroupId = transferGroupId;
    }

    // Preserve showCancelled state if checkbox exists
    var showCancelledCheckbox = $('#showCancelledCheckbox');
    if (showCancelledCheckbox.length && showCancelledCheckbox.is(':checked')) {
        data.showCancelled = '1';
    }

    // Preserve noGrouping state if checkbox exists
    var noGroupingCheckbox = $('#noGroupingCheckbox');
    if (noGroupingCheckbox.length && noGroupingCheckbox.is(':checked')) {
        data.noGrouping = '1';
    }

    // Store current device type filter BEFORE reload
    var currentDeviceTypeFilter = $('#deviceTypeFilter').val();

    $("#lastProduction").load('../public_html/components/production/last-production-table.php', data, function() {
        updateRollbackButtonState();

        // Restore device type filter after reload
        if (currentDeviceTypeFilter && currentDeviceTypeFilter !== 'all') {
            $('#deviceTypeFilter').val(currentDeviceTypeFilter);
            if (typeof window.filterTransfersByDeviceType === 'function') {
                window.filterTransfersByDeviceType(currentDeviceTypeFilter);
            }
        }
    });
}

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id];
        let option = null;
        if(version === null || version[0] === null) {
            option = "<option value='n/d' selected>n/d</option>";
        } else {
            option = "<option value='"+version[0]+"' selected>"+version[0]+"</option>";
        }
        $("#version").append(option);
        $("#version").selectpicker('destroy');
    } else {
        for (let version_id in possibleVersions)
        {
            let version = possibleVersions[version_id][0];
            let option = "<option value='"+version+"'>"+version+"</option>";
            $("#version").append(option);
        }
    }
    $("#version").selectpicker('refresh');
}

function generateLaminateSelect(possibleLaminates){
    if(Object.keys(possibleLaminates).length == 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"' selected>"+laminate_name+"</option>";
        $("#laminate").append(option);
        $("#laminate").selectpicker('destroy');
        $("#laminate").selectpicker('');
        generateVersionSelect(possibleLaminates[laminate_id]['versions']);
    } else {
        for (let laminate_id in possibleLaminates)
        {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"'>"+laminate_name+"</option>";
            $("#laminate").append(option);
        }
    }
    $("#laminate").selectpicker('refresh');
}

function generateMarking(marking){
    $("#marking").empty();
    for (let mark in marking)
    {
        mark = parseInt(mark);
        let bMarking = marking[mark];
        let fileName = (mark+1)+"off.png";
        if(bMarking) fileName = (mark+1)+"on.png";
        $("#marking").append(`<img style='width:33%;' 
                                   class='img-fluid mt-4' 
                                   src='/atte_ms_new/public_html/assets/img/production/tht/marking/`+fileName+`' 
                                   alt='oznaczenie'>`);
    }
}

function rollbackLastProduction() {
    let deviceId = $("#list__device").val();
    if (!deviceId) {
        alert("Najpierw wybierz urządzenie");
        return;
    }

    // Collect selected transfer groups and individual entries
    let selectedGroups = [];
    let selectedEntries = [];
    let groupsFullySelected = new Set();

    // Check which groups are fully selected via group checkbox
    $('.group-checkbox:checked').each(function() {
        let groupId = $(this).data('group-id');
        if (groupId) {
            selectedGroups.push(groupId);
            groupsFullySelected.add(groupId);
        }
    });

    // Check individual row checkboxes (only if their group is not fully selected)
    $('.row-checkbox:checked').each(function() {
        let rowId = $(this).data('row-id');
        let groupId = $(this).data('transfer-group-id');

        // Only include individual entries if their group isn't fully selected
        if (!groupsFullySelected.has(groupId)) {
            selectedEntries.push(rowId);
        }
    });

    if (selectedGroups.length === 0 && selectedEntries.length === 0) {
        alert("Brak zaznaczonych wpisów do cofnięcia");
        return;
    }

    // Populate modal and show it
    populateRollbackModal(selectedGroups, selectedEntries, groupsFullySelected);
    $('#rollbackProductionModal').modal('show');
}

function populateRollbackModal(selectedGroups, selectedEntries, groupsFullySelected) {
    const hasGroupSelection = selectedGroups.length > 0;

    // Show/hide appropriate view
    if (hasGroupSelection) {
        $('#groupedRollbackView').show();
        $('#flatRollbackView').hide();
        $('#groupRollbackWarning').show();

        let details = `<p>Zaznaczono <strong>${selectedGroups.length}</strong> grup transferowych.</p>`;
        if (selectedEntries.length > 0) {
            details += `<p>Oraz <strong>${selectedEntries.length}</strong> pojedynczych wpisów.</p>`;
        }
        $('#groupRollbackDetails').html(details);
    } else {
        $('#groupedRollbackView').hide();
        $('#flatRollbackView').show();
        $('#groupRollbackWarning').hide();
    }

    // Show loading
    const $container = hasGroupSelection ? $('#groupedRollbackView') : $('#rollbackSummaryBody');
    if (hasGroupSelection) {
        $container.html('<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Ładowanie...</p></div>');
    } else {
        $container.html('<tr><td colspan="6" class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Ładowanie...</p></td></tr>');
    }

    if (hasGroupSelection) {
        // Fetch all transfers for selected groups
        const fetchPromises = selectedGroups.map(groupId => {
            return $.ajax({
                type: 'POST',
                url: '../public_html/components/production/get-transfers-by-group.php',
                data: { transfer_group_id: groupId },
                dataType: 'json'
            });
        });

        Promise.all(fetchPromises).then(responses => {
            const allTransfers = [];
            responses.forEach(response => {
                if (response.success) {
                    allTransfers.push(...response.transfers);
                }
            });

            // Also fetch individual entries if any
            if (selectedEntries.length > 0) {
                return fetchIndividualEntries(selectedEntries, DEVICE_TYPE).then(individualTransfers => {
                    return [...allTransfers, ...individualTransfers];
                });
            }

            return allTransfers;
        }).then(allTransfers => {
            $('#rollbackCount').text(allTransfers.length);
            renderHierarchicalRollback(allTransfers, $('#groupedRollbackView'));
        }).catch(error => {
            $container.html('<div class="text-center text-danger py-4">Błąd ładowania danych</div>');
        });
    } else {
        // Fetch only individual entries
        fetchIndividualEntries(selectedEntries, DEVICE_TYPE).then(transfers => {
            $('#rollbackCount').text(transfers.length);
            renderFlatRollback(transfers, $('#rollbackSummaryBody'));
        }).catch(error => {
            $container.html('<tr><td colspan="6" class="text-center text-danger">Błąd ładowania danych</td></tr>');
        });
    }
}

function fetchIndividualEntries(entryIds, deviceType) {
    return $.ajax({
        type: 'POST',
        url: '../public_html/components/production/get-entries-by-ids.php',
        data: {
            entry_ids: entryIds.join(','),
            device_type: deviceType
        },
        dataType: 'json'
    }).then(response => {
        return response.success ? response.transfers : [];
    });
}

/**
 * Render transfers hierarchically: grouped by transfer group, then by device
 * Matches Archive modal structure exactly (three-level hierarchy)
 */
function renderHierarchicalRollback(allTransfers, $container) {
    // Clear container and create table structure
    $container.empty();

    const $table = $('<table class="table table-sm table-bordered mb-0"></table>');
    const $tbody = $('<tbody></tbody>');
    $table.append($tbody);
    $container.append($table);

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

        // Transfer group header row (Level 1 - Cyan background)
        const groupHeaderRow = `
            <tr class="modal-transfer-group-header"
                data-group-key="${groupKey}"
                data-group-id="${groupId}"
                style="cursor: pointer; background-color: #d1ecf1; font-weight: 600; border-top: 2px solid #0c5460;">
                <td colspan="6">
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
        $tbody.append(groupHeaderRow);

        // Group content container (collapsible)
        const groupContentStart = `
            <tr class="modal-transfer-group-content collapse"
                data-group-key="${groupKey}">
                <td colspan="6" class="p-0">
                    <table class="table table-sm mb-0" style="background-color: #f8f9fa;">
                        <tbody>
        `;
        $tbody.append(groupContentStart);

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

            // Device header row (Level 2 - Gray background, nested under group)
            const deviceHeaderRow = `
                <tr class="modal-device-group-header collapse modal-transfer-group-content"
                    data-group-key="${groupKey}"
                    data-device-key="${fullDeviceKey}"
                    data-device-index="${deviceIndex}"
                    style="cursor: pointer; background-color: #e9ecef; font-weight: 500;">
                    <td colspan="6" style="padding-left: 30px;">
                        <i class="bi bi-chevron-right toggle-icon-device-modal" style="transition: transform 0.2s;"></i>
                        ${deviceTypeBadge}
                        <strong>${escapeHtml(deviceData.deviceName)}</strong>
                        <span class="badge badge-secondary ml-2">${activeTransferCount} wierszy</span>
                        ${cancelledBadge}
                        <span class="text-muted ml-2 device-quantity-display">Łącznie: ${deviceQtyText}</span>
                    </td>
                </tr>
            `;
            $tbody.append(deviceHeaderRow);

            // Device content (nested table - Level 3)
            const deviceContentStart = `
                <tr class="modal-device-group-content collapse"
                    data-device-key="${fullDeviceKey}">
                    <td colspan="6" class="p-0">
                        <table class="table table-sm mb-0" style="background-color: #fff;">
                            <tbody>
            `;
            $tbody.append(deviceContentStart);

            // Render individual transfers (Level 3 - White background)
            deviceData.transfers.forEach(transfer => {
                const transferUserName = `${transfer.user_name || ''} ${transfer.user_surname || ''}`.trim() || '-';
                const transferTypeBadge = getDeviceTypeBadge(transfer.device_type);
                const transferId = transfer.id;
                const isCancelled = transfer.is_cancelled == 1;
                const cancelledClass = isCancelled ? 'cancelled-row' : '';
                const cancelledBadge = isCancelled ? '<span class="badge badge-danger badge-sm ml-1">Anulowany</span>' : '';

                const transferRowHtml = `
                    <tr class="modal-device-transfer-row collapse modal-device-group-content ${cancelledClass}"
                        data-device-key="${fullDeviceKey}"
                        data-transfer-id="${transferId}"
                        data-is-cancelled="${isCancelled ? '1' : '0'}">
                        <td style="padding-left: 60px;">${escapeHtml(transferUserName)}</td>
                        <td>${transferTypeBadge} ${escapeHtml(transfer.device_name)}${cancelledBadge}</td>
                        <td>${escapeHtml(transfer.input_type_name || '-')}</td>
                        <td>${transfer.qty > 0 ? '+' : ''}${parseFloat(transfer.qty).toFixed(2)}</td>
                        <td>${escapeHtml(transfer.comment || '')}</td>
                        <td>${formatDateTime(transfer.timestamp)}</td>
                    </tr>
                `;
                $tbody.append(transferRowHtml);
            });

            // Close device content table
            const deviceContentEnd = `
                            </tbody>
                        </table>
                    </td>
                </tr>
            `;
            $tbody.append(deviceContentEnd);

            deviceIndex++;
        });

        // Close group content table
        const groupContentEnd = `
                        </tbody>
                    </table>
                </td>
            </tr>
        `;
        $tbody.append(groupContentEnd);

        groupIndex++;
    });

    // Attach event handlers for collapse toggles
    attachRollbackModalEventHandlers();
}

/**
 * Attach event handlers for rollback modal collapse toggles
 */
function attachRollbackModalEventHandlers() {
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
}

/**
 * Render flat rollback table (for individual transfers without groups)
 * Matches Archive modal flat table structure
 */
function renderFlatRollback(transfers, $tbody) {
    $tbody.empty();

    transfers.forEach(transfer => {
        const transferUserName = `${transfer.user_name || ''} ${transfer.user_surname || ''}`.trim() || '-';
        const deviceTypeBadge = getDeviceTypeBadge(transfer.device_type || DEVICE_TYPE);
        const isCancelled = transfer.is_cancelled == 1;
        const cancelledClass = isCancelled ? 'cancelled-row' : '';
        const cancelledBadge = isCancelled ? '<span class="badge badge-danger badge-sm ml-1">Anulowany</span>' : '';

        $tbody.append(`
            <tr class="${cancelledClass}">
                <td>${escapeHtml(transferUserName)}</td>
                <td>${deviceTypeBadge} ${escapeHtml(transfer.device_name || 'N/A')}${cancelledBadge}</td>
                <td>${escapeHtml(transfer.input_type_name || '-')}</td>
                <td>${transfer.qty > 0 ? '+' : ''}${parseFloat(transfer.qty).toFixed(2)}</td>
                <td>${escapeHtml(transfer.comment || '')}</td>
                <td>${formatDateTime(transfer.timestamp)}</td>
            </tr>
        `);
    });
}

$("#list__device").change(function(){
    if (IS_SMD) {
        $("#laminate, #version").empty();
        $("#laminate, #version").selectpicker('refresh');
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        let deviceDescription = $("#list__device option:selected").data("subtext");
        $("#device_description").val(deviceDescription);
        generateLaminateSelect(possibleLaminates);
    } else {
        $("#version, #alerts").empty();
        $("#version").selectpicker('refresh');
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        let marking = $("#list__device option:selected").data("jsonmarking");
        let deviceDescription = $("#list__device option:selected").data("subtext");
        $("#device_description").val(deviceDescription);
        generateVersionSelect(possibleVersions);
        generateMarking(marking);
    }
    generateLastProduction(this.value);
});

if (IS_SMD) {
    $("#laminate").change(function(){
        let possibleVersions = $("#laminate option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    });
}

$("#form").submit(function(e) {
    e.preventDefault();
    if($("#quantity").val() < 0 && !$.trim($("#comment").val()))
    {
        $('#correctionModal').modal('show');
        return;
    }
    $("#send").html("Wysyłanie");
    $("#send").prop("disabled", true);
    var $form = $(this);
    var actionUrl = $form.attr('action');
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: $form.serialize(),
        success: function(data)
        {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            $("#alerts").empty();

            try {
                const result = JSON.parse(data);
                let transferGroupId = result[0];
                let alerts = result[1];

                generateLastProduction($("#list__device option:selected").val(), transferGroupId);

                alerts.forEach(function(alert) {
                    $("#alerts").append(alert);
                });
            } catch (e) {
                $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                    data +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span>' +
                    '</button>' +
                    '</div>');
            }
        },
        error: function(xhr, status, error) {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            $("#alerts").empty();

            let errorMessage = "Wystąpił błąd podczas przetwarzania żądania.";
            if (xhr.responseText) {
                errorMessage = xhr.responseText;
            }

            $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                errorMessage +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span>' +
                '</button>' +
                '</div>');
        }
    });
});

function updateRollbackButtonState() {
    var $rollbackBtn = $('#rollbackBtn');

    // Count selected groups
    var selectedGroups = $('.group-checkbox:checked').length;

    // Count selected individual rows (excluding those in fully selected groups)
    var groupsFullySelected = new Set();
    $('.group-checkbox:checked').each(function() {
        var groupId = $(this).data('group-id');
        if (groupId) groupsFullySelected.add(groupId);
    });

    var selectedIndividualRows = 0;
    $('.row-checkbox:checked').each(function() {
        var groupId = $(this).data('transfer-group-id');
        if (!groupsFullySelected.has(groupId)) {
            selectedIndividualRows++;
        }
    });

    var totalSelected = selectedGroups + selectedIndividualRows;

    if (totalSelected > 0) {
        $rollbackBtn.prop('disabled', false).removeClass('btn-secondary').addClass('btn-warning');

        if (selectedGroups > 0 && selectedIndividualRows > 0) {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedGroups + ' grup, ' + selectedIndividualRows + ' wpisów)');
        } else if (selectedGroups > 0) {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedGroups + ' grup)');
        } else {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedIndividualRows + ' wpisów)');
        }
    } else {
        $rollbackBtn.prop('disabled', true).removeClass('btn-warning').addClass('btn-secondary');
        $rollbackBtn.text('Cofnij zaznaczone');
    }
}

// Handle modal confirm button
$('#confirmRollback').click(function() {
    $('#rollbackProductionModal').modal('hide');

    let deviceId = $("#list__device").val();
    let selectedGroups = [];
    let selectedEntries = [];
    let groupsFullySelected = new Set();

    // Re-collect selections (in case modal was open for a while)
    $('.group-checkbox:checked').each(function() {
        let groupId = $(this).data('group-id');
        if (groupId) {
            selectedGroups.push(groupId);
            groupsFullySelected.add(groupId);
        }
    });

    $('.row-checkbox:checked').each(function() {
        let rowId = $(this).data('row-id');
        let groupId = $(this).data('transfer-group-id');
        if (!groupsFullySelected.has(groupId)) {
            selectedEntries.push(rowId);
        }
    });

    $("#rollbackBtn").html("Cofanie...").prop("disabled", true);

    $.ajax({
        type: "POST",
        url: "../public_html/components/production/rollback-production.php",
        data: {
            deviceType: DEVICE_TYPE,
            deviceId: deviceId,
            transferGroupIds: selectedGroups.join(','),
            entryIds: selectedEntries.join(',')
        },
        success: function(data) {
            const result = JSON.parse(data);
            $("#rollbackBtn").html("Cofnij zaznaczone").prop("disabled", true);

            if (result.success) {
                generateLastProduction(deviceId, result.transferGroupId);
                $("#alerts").empty();
                $("#alerts").append('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                    result.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

                if (result.alerts && result.alerts.length > 0) {
                    result.alerts.forEach(function(alert) {
                        $("#alerts").append(alert);
                    });
                }
            } else {
                $("#alerts").empty();
                $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                    result.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            $("#rollbackBtn").html("Cofnij zaznaczone").prop("disabled", true);
            $("#alerts").empty();
            $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                'Błąd podczas cofania produkcji' +
                '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        }
    });
});

$(document).ready(function(){
    updateRollbackButtonState();
    let autoSelectValues = JSON.parse($("#list__device").attr("data-auto-select"));
    if(autoSelectValues.length) {
        $("#list__device").selectpicker('val', autoSelectValues[0]).change();
        if (IS_SMD && autoSelectValues.length > 1) {
            $("#laminate").selectpicker('val', autoSelectValues[1]).change();
            if (autoSelectValues.length > 2) {
                $("#version").selectpicker('val', autoSelectValues[2]).change();
            }
        } else if (!IS_SMD && autoSelectValues.length > 1) {
            $("#version").selectpicker('val', autoSelectValues[1]).change();
        }
    }
});