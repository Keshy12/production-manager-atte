let commissionCard = $('script[data-template="commissionCard"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function renderCards(results) {
    for (let result in results) {
        let items = Array.isArray(results[result]) ? results[result] : [results[result]];
        $("#container")
            .append(items.map(function (item) {
                return commissionCard.map(render(item)).join('');
            }));
    }
    $('[data-toggle="popover"]').popover()
}

function renderCommissions(){
    $("#previouspage").prop('disabled', true);
    $("#nextpage").prop('disabled', true);
    let transferFrom = $("#transferFrom").val();
    let transferTo = $("#transferTo").val();
    let device = [$("#type").val(), $("#list__device").val(), $("#laminate").val(), $("#version").val()];
    let receivers = $("#user").val();
    let state_id = $("#state").val();
    let priority_id = $("#priority").val();
    let showCancelled = $("#showCancelled").prop('checked');
    let page = $("#currentpage").text();
    $.ajax({
        type: "POST",
        url:  COMPONENTS_PATH+"/commissions/get-commissions.php",
        data: {transferFrom: transferFrom, transferTo: transferTo, device: device, receivers: receivers, state_id: state_id, priority_id: priority_id, showCancelled: showCancelled, page: page},
        success: function(data)
        {
            let result = JSON.parse(data);
            let nextPageAvailable = result[1];
            $("#previouspage").prop('disabled', page==1);
            $("#nextpage").prop('disabled', !nextPageAvailable);
            $("#container").empty();
            $("#transferSpinner").hide();
            renderCards(result[0]);
        }
    });
}

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        if(possibleVersions[0] == null)
        {
            $("#version").selectpicker('destroy');
            $("#version").html("<option value=\"n/d\" selected>n/d</option>");
            $("#version").prop('disabled', false);
            $("#version").selectpicker('refresh');
            $("#currentpage").text(1);
            renderCommissions();
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $("#version").append(option);
        $("#version").selectpicker('destroy');
        $("#currentpage").text(1);
        renderCommissions();
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
        $("#version").prop('disabled', false);
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

$(document).ready(function(){
    $("[data-toggle=popover]").popover();
    renderCommissions();
})

$("#type").change(function(){
    $("#list__device").empty();
    $('#list__'+this.value+' option').clone().appendTo('#list__device');
    $('#list__device').prop("disabled", false);
    $('.selectpicker').selectpicker('refresh');
    $("#version").empty();
    $("#version, #laminate").prop('disabled', true);
    $("#list__laminate").hide();
    if(this.value == 'smd') $("#list__laminate").show();
    $("#version, #laminate").selectpicker('refresh');
});

$("#list__device").change(function(){
    $("#version").empty();
    $("#laminate").empty();
    if($("#type").val() == 'smd') {
        $("#laminate").prop('disabled', false);
        $("#version").prop('disabled', true);
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        generateLaminateSelect(possibleLaminates);
    } else if($("#type").val() == 'tht') {
        $("#version").prop('disabled', false);
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    } else {
        $("#version").selectpicker('destroy');
        $("#version").html("<option value=\"n/d\" selected>n/d</option>");
        $("#version").prop('disabled', false);
        renderCommissions();
    }
    $("#version, #laminate").selectpicker('refresh');
});

$("#list__laminate").change(function(){
    let possibleVersions = $("#laminate option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#version").prop('disabled', false);
    $("#version").selectpicker('refresh');
});

$("#clearDevice").click(function(){
    $("#currentpage").text(1);
    $("#list__laminate").hide();
    $('#type').val(0)
    $("#list__device, #version, #laminate").empty();
    $("#type, #list__device, #version, #laminate").selectpicker('refresh');
    renderCommissions();
});

$("#clearMagazine").click(function(){
    $("#currentpage").text(1);
    $('#transferFrom, #transferTo').val('')
    $('#transferFrom, #transferTo').selectpicker('refresh');
    generateUserSelect('');
    renderCommissions();
});

$("#transferFrom, #transferTo, #version, #showCancelled").change(function(){
    $("#currentpage").text(1);
    renderCommissions();
});

$("#user, #state, #priority").on('hide.bs.select', function(){
    $("#currentpage").text(1);
    renderCommissions();
});

$("#previouspage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    if (page != 1) {
        page--;
        $("#currentpage").text(page);
        renderCommissions();
    }
});

$("#nextpage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    page++;
    $("#currentpage").text(page);
    renderCommissions();
});

$("#transferTo").change(function(){
    const transferTo = this.value;
    generateUserSelect(transferTo);
});

function generateUserSelect(submagId) {
    $("#user option").each(function() {
        $(this)
            .prop("disabled",
                (submagId !== '' &&
                    $(this).attr("data-submag-id") !== submagId ))
            .prop("selected", false);
    });
    $(".selectpicker").selectpicker('refresh');
}

$("body").on('click', '.editCommission', function(){
    let id = $(this).attr("data-id");
    let receivers = $(this).attr("data-receivers");
    let submagId = $(this).attr("data-submag-id");
    let priority = $(this).attr("data-priority");
    $("#editPriority").val(priority);
    $("#editSubcontractors").empty();
    $("#user option").each(function(){
        if($(this).attr("data-submag-id") == submagId) {
            $(this).clone().appendTo('#editSubcontractors');
        }
    });
    $("#editSubcontractors").selectpicker('refresh');
    receivers = receivers.split(',');
    receivers = $.map(receivers, $.trim);
    $("#editSubcontractors").selectpicker('val', receivers)
    $("#editCommissionModal").modal("show");
    $(".selectpicker").selectpicker('refresh');
    $("#editCommissionSubmit").attr("data-id", id);
});

$("#editCommissionSubmit").click(function(){
    const commissionId = $(this).attr("data-id");
    const commissionPriority = $("#editPriority").val();
    const commissionSubcontractors = $("#editSubcontractors").val();
    const data = {id: commissionId, priority: commissionPriority, receivers: commissionSubcontractors};
    editCommission(data);
    $("#editCommissionModal").modal("hide");
    renderCommissions();
});

function editCommission(data) {
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/commissions/edit-commission.php",
        data: data,
        async: true,
        success: function(response) {
            const result = JSON.parse(response);
            const wasSuccessful = result[0];
            const errorMessage = result[1];
            let resultMessage = wasSuccessful ?
                "Edytowanie danych powiodło się." :
                errorMessage;
            let resultAlertType = wasSuccessful ?
                "alert-success" :
                "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

// Enhanced Commission Cancellation Functionality
let currentCancellationData = {
    commissionId: null,
    commissionGroups: [],
    selectedGroups: new Set(),
    manualUsageAdjustments: {}
};

$('body').on('click', '.cancelCommission', function() {
    const commissionId = $(this).attr("data-id");
    currentCancellationData.commissionId = commissionId;
    loadCommissionGroups(commissionId);
});

function loadCommissionGroups(commissionId) {
    $("#cancelModalOverlay").show();

    if (!currentCancellationData.manualUsageAdjustments) {
        currentCancellationData.manualUsageAdjustments = {};
    }

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/commissions/get-commission-groups.php",
        data: { commissionId: commissionId },
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                currentCancellationData.commissionGroups = result.data;
                displayCommissionGroups(result.data);
                $("#cancelCommissionModal").modal("show");
            } else {
                showAlert("Błąd ładowania grup zleceń: " + result.message, "danger");
            }
        },
        error: function() {
            showAlert("Błąd podczas ładowania grup zleceń", "danger");
        },
        complete: function() {
            $("#cancelModalOverlay").hide();
        }
    });
}

function displayCommissionGroups(groups) {
    const container = $("#groupsList");
    container.empty();

    if (groups.length === 0) {
        container.html('<div class="alert alert-info">Nie znaleziono grup transferów dla tego zlecenia.</div>');
        return;
    }

    groups.forEach((group, groupIndex) => {
        // Build commissions list HTML
        const commissionsHtml = group.allCommissions.map((commission, index) => {
            const statusBadge = commission.isCancelled ?
                '<span class="badge badge-danger">Anulowane</span>' :
                (commission.stateId == 3 ? '<span class="badge badge-success">Zakończone</span>' : '');

            const currentBadge = (commission.isCurrentCommission && commission.commissionId === parseInt(currentCancellationData.commissionId)) ?
                '<span class="badge badge-warning">Bieżące</span>' : '';

            let extensionBadgeHtml = '';
            if (commission.extensionBadge === 'requires_all') {
                extensionBadgeHtml = '<span class="badge badge-info requiresAllBadge">Wymaga wszystkich rozszerzeń</span>';
            } else if (commission.extensionBadge === 'partial_only') {
                extensionBadgeHtml = '<span class="badge badge-danger">Tylko rozszerzenie</span>';
            }

            const laminateInfo = commission.laminate ? ` lam. ${commission.laminate}` : '';

            // Build transfers with individual checkboxes
            const transfersHtml = commission.transfers.length > 0 ?
                commission.transfers.map((transfer, transferIndex) => {
                    // If transfer has multiple sources, create separate rows for each source
                    if (transfer.sources && transfer.sources.length > 1) {
                        return transfer.sources.map((source, sourceIndex) => {
                            // Parse quantity from source string "SourceName (quantity)"
                            const quantityMatch = source.match(/\((\d+(?:\.\d+)?)\)/);
                            const quantity = quantityMatch ? quantityMatch[1] : transfer.quantity;
                            const sourceName = source.replace(/\s*\(.*?\)\s*$/, '').trim();

                            return `
                    <tr>
                        <td>
                            <div class="form-check">
                                <input class="form-check-input transfer-checkbox" type="checkbox" 
                                       id="transfer_${groupIndex}_${commission.commissionId || 'manual_' + index}_${transferIndex}_${sourceIndex}"
                                       data-commission-id="${commission.commissionId || 'manual'}"
                                       data-transfer-id="${commission.transferId}"
                                       data-is-manual="${commission.isManualComponents || false}"
                                       data-is-extension="${commission.isExtension || false}"
                                       data-group-id="${group.id}"
                                       data-quantity="${commission.quantity}"
                                       data-quantity-produced="${commission.quantityProduced}"
                                       data-quantity-returned="${commission.quantityReturned}"
                                       data-receivers="${commission.receivers.join(', ')}"
                                       data-component="${transfer.componentName}"
                                       ${commission.isCurrentCommission && commission.commissionId === parseInt(currentCancellationData.commissionId)
                                ? 'checked' : ''}>

                                <label class="form-check-label" for="transfer_${groupIndex}_${commission.commissionId || 'manual_' + index}_${transferIndex}_${sourceIndex}">
                                    <strong>${transfer.componentName}</strong>
                                    ${transfer.componentDescription ? `<br><small class="text-muted">${transfer.componentDescription}</small>` : ''}
                                </label>
                            </div>
                        </td>
                        <td><span class="badge badge-secondary">${quantity}</span></td>
                        <td><small>${sourceName} → ${transfer.destination}</small></td>
                    </tr>
                `;
                        }).join('');
                    } else {
                        // Single source - display as before
                        const statusBadge = commission.isCancelled ?
                            '<span class="badge badge-danger">Anulowane</span>' :
                            (commission.stateId == 3 ? '<span class="badge badge-success">Zakończone</span>' : '');

                        const currentBadge = (commission.isCurrentCommission && commission.commissionId === parseInt(currentCancellationData.commissionId)) ?
                            '<span class="badge badge-warning">Bieżące</span>' : '';

                        return `
                <tr>
                    <td>
                        <div class="form-check">
                            <input class="form-check-input transfer-checkbox" type="checkbox" 
                                   id="transfer_${groupIndex}_${commission.commissionId || 'manual_' + index}_${transferIndex}"
                                   data-commission-id="${commission.commissionId || 'manual'}"
                                   data-transfer-id="${commission.transferId}"
                                   data-is-manual="${commission.isManualComponents || false}"
                                   data-is-extension="${commission.isExtension || false}"
                                   data-group-id="${group.id}"
                                   data-quantity="${commission.quantity}"
                                   data-quantity-produced="${commission.quantityProduced}"
                                   data-quantity-returned="${commission.quantityReturned}"
                                   data-receivers="${commission.receivers.join(', ')}"
                                   data-component="${transfer.componentName}"
                                   ${commission.isCurrentCommission && commission.commissionId === parseInt(currentCancellationData.commissionId)
                            ? 'checked' : ''}>

                            <label class="form-check-label" for="transfer_${groupIndex}_${commission.commissionId || 'manual_' + index}_${transferIndex}">
                                <strong>${transfer.componentName}</strong>
                                ${transfer.componentDescription ? `<br><small class="text-muted">${transfer.componentDescription}</small>` : ''}
                            </label>
                        </div>
                    </td>
                    <td><span class="badge badge-secondary">${transfer.quantity}</span></td>
                    <td><small>${transfer.sources.join(', ')} → ${transfer.destination}</small></td>
                </tr>
            `;
                    }
                }).join('') :
                '<tr><td colspan="3" class="text-muted text-center">Brak transferów</td></tr>';

            const commissionTitle = commission.isManualComponents ?
                'Komponenty dodane ręcznie' :
                `${commission.deviceName}${laminateInfo} ver. ${commission.version}`;

            const transferCollapseId = `transferCollapse_${groupIndex}_${index}`;

            return `
                <div class="commission-container mb-3" data-group-id="${group.id}">
                    <div class="d-flex align-items-center mb-2">
                        <div class="form-check mr-3">
                            <input class="form-check-input commission-checkbox" type="checkbox" 
                                   id="commission_${groupIndex}_${commission.commissionId || 'manual_' + index}"
                                   data-commission-id="${commission.commissionId || 'manual'}"
                                   data-transfer-id="${commission.transferId}"
                                   data-is-manual="${commission.isManualComponents || false}"
                                   data-is-extension="${commission.isExtension || false}"
                                   data-group-id="${group.id}"
                                   data-quantity="${commission.quantity || '-'}"
                                   data-quantity-produced="${commission.quantityProduced || '0'}"
                                   data-quantity-returned="${commission.quantityReturned || '0'}"
                                   data-receivers="${commission.receivers.join(', ')}"
                                   ${commission.isCurrentCommission && commission.commissionId === parseInt(currentCancellationData.commissionId)
                ? 'checked' : ''}>
                            <label class="form-check-label" for="commission_${groupIndex}_${commission.commissionId || 'manual_' + index}">
                                <strong>${commissionTitle}</strong>
                            </label>
                        </div>
                        <div class="flex-grow-1 badgesContainer">
                            ${currentBadge} ${statusBadge} ${extensionBadgeHtml}
                            ${!commission.isManualComponents ? `
                                <div><small class="text-muted">
                                    ${commission.isExtension ? 'Rozszerzenie' : commission.quantity + ' szt.'} | 
                                    Wyprodukowano: ${commission.quantityProduced} | 
                                    Odbiorcy: ${commission.receivers.join(', ')}
                                </small></div>
                            ` : ''}
                        </div>
                    </div>
                    
                    ${commission.transfers.length > 0 ? `
                        <div class="ml-4">
                            <button class="btn btn-link btn-sm p-0 mb-2" type="button" 
                                    data-toggle="collapse" data-target="#${transferCollapseId}" 
                                    aria-expanded="false">
                                <i class="fas fa-chevron-right"></i> Transferowane komponenty (${commission.transfers.length})
                            </button>
                            <div class="collapse" id="${transferCollapseId}">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th width="50%">Komponent</th>
                                            <th width="15%">Ilość</th>
                                            <th width="35%">Transfer</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${transfersHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');

        // Count items in group for indicator - will be updated dynamically
        const itemCount = group.allCommissions.length;

        const groupHtml = `
            <div class="card mb-3" data-group-id="${group.id}">
                <div class="card-header" data-toggle="collapse" data-target="#groupCollapse_${groupIndex}" 
                     style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-chevron-right mr-2"></i>
                            <strong>Transfer #${group.id}</strong>
                            <small class="text-muted ml-2">${group.timestamp}</small>
                            <span class="badge badge-secondary ml-2 group-selection-indicator" data-group-id="${group.id}">0/${itemCount}</span>
                        </div>
                    </div>
                </div>
                <div id="groupCollapse_${groupIndex}" class="collapse">
                    <div class="card-body">
                        ${commissionsHtml}
                    </div>
                </div>
            </div>
        `;
        container.append(groupHtml);
    });

    // Add event listeners for collapsible headers
    $('[data-toggle="collapse"]').on('click', function() {
        const target = $($(this).data('target'));
        const icon = $(this).find('.fa-chevron-right, .fa-chevron-down');

        target.on('shown.bs.collapse', function() {
            icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
        });

        target.on('hidden.bs.collapse', function() {
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
        });
    });

    // Commission checkbox: auto-select/deselect all its components
    $(".commission-checkbox").change(function() {
        const isChecked = $(this).is(':checked');
        const commissionContainer = $(this).closest('.commission-container');

        // Auto-select/deselect all transfers for this commission
        commissionContainer.find('.transfer-checkbox').prop('checked', isChecked);

        updateSelection();
    });

    // Transfer checkbox: independent operation
    $(".transfer-checkbox").change(function() {
        updateSelection();
    });

    // Trigger initial update
    updateSelection();
}

function updateSelection() {
    const selectedCommissions = new Set();
    const selectedTransfers = [];

    // Collect checked commission checkboxes
    $(".commission-checkbox:checked").each(function() {
        const commissionId = $(this).data('commission-id');
        selectedCommissions.add(commissionId);
    });

    // Collect checked transfer checkboxes
    $(".transfer-checkbox:checked").each(function() {
        selectedTransfers.push({
            commissionId: $(this).data('commission-id'),
            transferId: $(this).data('transfer-id'),
            component: $(this).data('component')
        });
    });

    // Update group selection indicators
    $('.group-selection-indicator').each(function() {
        const groupId = $(this).data('group-id');
        const $groupCard = $(`.card[data-group-id="${groupId}"]`);
        const totalCommissions = $groupCard.find('.commission-checkbox').length;
        const selectedCommissionsInGroup = $groupCard.find('.commission-checkbox:checked').length;

        $(this).text(`${selectedCommissionsInGroup}/${totalCommissions}`);

        // Hide badge if it's 1/1
        if (selectedCommissionsInGroup === 1 && totalCommissions === 1) {
            $(this).hide();
        } else {
            $(this).show();
        }
    });

    // Track all instances of each commission across all groups
    const commissionInstances = {};

    $('.commission-checkbox').each(function() {
        const commissionId = $(this).data('commission-id');

        if (commissionId && commissionId !== 'manual') {
            if (!commissionInstances[commissionId]) {
                commissionInstances[commissionId] = {
                    total: 0,
                    selected: 0,
                    containers: []
                };
            }

            commissionInstances[commissionId].total++;
            commissionInstances[commissionId].containers.push($(this).closest('.commission-container'));

            if ($(this).is(':checked')) {
                commissionInstances[commissionId].selected++;
            }
        }
    });

    // Apply visual indicators based on whether ALL instances are selected
    $('.commission-container').each(function() {
        const $container = $(this);
        const $commissionCheckbox = $container.find('.commission-checkbox');
        const commissionId = $commissionCheckbox.data('commission-id');

        // Remove existing indicators
        $container.removeClass('commission-will-be-cancelled');
        $container.find('.commission-cancellation-badge').remove();
        $container.find('.badge-info').show();

        if (commissionId && commissionId !== 'manual' && commissionInstances[commissionId]) {
            const allInstancesSelected = commissionInstances[commissionId].selected === commissionInstances[commissionId].total;

            // Check if this commission has "Tylko rozszerzenie" badge
            const hasPartialOnlyBadge = $container.find('.badge-danger').text().includes('Tylko rozszerzenie');

            // Add visual indicator only if ALL instances are selected AND it's not a "partial only" commission
            if (allInstancesSelected && !hasPartialOnlyBadge) {
                $container.addClass('commission-will-be-cancelled');

                // Add the cancellation badge to the badges container
                let $badgeContainer = $container.find('.badgesContainer');
                $badgeContainer.append(
                    '<span class="badge commission-cancellation-badge ml-2">Całe zlecenie zostanie anulowane</span>'
                );

                // Hide the "Wymaga wszystkich rozszerzeń" badge if commission will be cancelled
                $badgeContainer.find('.badge-info').hide();
            }
        }
    });

    // Enable/disable confirm button
    $("#confirmCancellation").prop('disabled', selectedTransfers.length === 0);

    // Store selection data
    currentCancellationData.selectedCommissions = selectedCommissions;
    currentCancellationData.selectedTransfers = selectedTransfers;

    generateCancellationSummary();
}

function generateCancellationSummary() {
    const summary = {
        commissionsToCancel: [],
        transfersToCancel: [],
        hasSelections: false,
        allTransfers: []
    };

    let hasNegativeRemaining = false;

    // Group selected transfers by commission - track commission checkboxes
    const commissionInfo = {};

    // Track all instances of each commission
    const commissionInstances = {};

    $('.commission-checkbox').each(function() {
        const $checkbox = $(this);
        const commissionId = $checkbox.data('commission-id');

        if (commissionId && commissionId !== 'manual') {
            if (!commissionInstances[commissionId]) {
                commissionInstances[commissionId] = {
                    total: 0,
                    selected: 0
                };
            }

            commissionInstances[commissionId].total++;

            if ($checkbox.is(':checked')) {
                commissionInstances[commissionId].selected++;
            }
        }
    });

    // Collect commission checkbox states and transfer details
    $('.commission-checkbox').each(function() {
        const $checkbox = $(this);
        const commissionId = $checkbox.data('commission-id');

        if (commissionId && commissionId !== 'manual') {
            const deviceName = $checkbox.next('label').text().trim();
            const isChecked = $checkbox.is(':checked');
            const $container = $checkbox.closest('.commission-container');

            // Get data directly from data attributes
            const quantity = $checkbox.data('quantity') || '-';
            const quantityProduced = $checkbox.data('quantity-produced') || '0';
            const quantityReturned = $checkbox.data('quantity-returned') || '0';
            const receivers = $checkbox.data('receivers') || 'Brak';

            if (!commissionInfo[commissionId]) {
                commissionInfo[commissionId] = {
                    deviceName: deviceName,
                    quantity: quantity,
                    quantityProduced: quantityProduced,
                    quantityReturned: quantityReturned,
                    receivers: receivers,
                    instances: [],
                    totalSelectedTransfers: 0,
                    totalAvailableTransfers: 0
                };
            }

            // Count transfers in this instance - only count actual transfer checkboxes
            const allTransferCheckboxes = $container.find('.transfer-checkbox');
            const checkedTransferCheckboxes = $container.find('.transfer-checkbox:checked');

            const transferCount = allTransferCheckboxes.length;
            const selectedCount = checkedTransferCheckboxes.length;

            commissionInfo[commissionId].instances.push({
                selected: selectedCount,
                total: transferCount,
                container: $container
            });

            commissionInfo[commissionId].totalSelectedTransfers += selectedCount;
            commissionInfo[commissionId].totalAvailableTransfers += transferCount;

            // Collect transfer details for rollback summary
            checkedTransferCheckboxes.each(function() {
                const $transferCheckbox = $(this);
                const $transferRow = $transferCheckbox.closest('tr');
                const componentName = $transferCheckbox.data('component');
                const commissionId = $transferCheckbox.data('commission-id');
                const groupId = $transferCheckbox.data('group-id');

                // Get device name from the commission container
                const $commissionContainer = $transferCheckbox.closest('.commission-container');
                const $commissionCheckbox = $commissionContainer.find('.commission-checkbox');
                const deviceName = $commissionCheckbox.next('label').find('strong').text().trim();

                // Get quantity from the badge in this specific row
                const quantityText = $transferRow.find('td:eq(1) .badge').text().trim();
                const quantity = parseFloat(quantityText) || 0;

                // Get transfer info from this specific row
                const transferText = $transferRow.find('td:eq(2) small').text().trim();

                // Parse transfer direction (source → destination)
                const transferMatch = transferText.match(/(.+?)\s*→\s*(.+)/);

                if (transferMatch) {
                    const sourceName = transferMatch[1].trim();
                    const destination = transferMatch[2].trim();

                    // Find the original transfer data with sourceIds from backend
                    let sourceIds = [];
                    const group = currentCancellationData.commissionGroups.find(g => g.id === groupId);
                    if (group) {
                        const commission = group.allCommissions.find(c => c.commissionId === commissionId);
                        if (commission && commission.transfers) {
                            const transfer = commission.transfers.find(t => t.componentName === componentName);
                            if (transfer && transfer.sourceIds) {
                                sourceIds = transfer.sourceIds;
                            }
                        }
                    }

                    const transferDetail = {
                        commissionId: commissionId,
                        deviceName: deviceName,
                        componentName: componentName,
                        quantity: quantity,
                        sources: [`${sourceName} (${quantity})`],
                        sourceIds: sourceIds,
                        destination: destination,
                        fullTransferText: transferText
                    };

                    summary.allTransfers.push(transferDetail);
                }
            });
        }
    });

    // Determine which commissions will be fully cancelled vs partially
    for (const [commissionId, info] of Object.entries(commissionInfo)) {
        if (info.totalSelectedTransfers > 0) {
            summary.hasSelections = true;

            const allInstancesSelected = commissionInstances[commissionId] &&
                commissionInstances[commissionId].selected === commissionInstances[commissionId].total;

            // Check if any instance of this commission has "Tylko rozszerzenie" badge
            let hasPartialOnlyBadge = false;
            info.instances.forEach(instance => {
                if (instance.container.find('.badge-danger').text().includes('Tylko rozszerzenie')) {
                    hasPartialOnlyBadge = true;
                }
            });

            const commissionData = {
                id: commissionId,
                deviceName: info.deviceName,
                quantity: info.quantity,
                quantityProduced: info.quantityProduced,
                quantityReturned: info.quantityReturned,
                receivers: info.receivers,
                selectedTransfers: info.totalSelectedTransfers,
                totalTransfers: info.totalAvailableTransfers
            };

            // Only consider it for full cancellation if ALL instances are selected AND it doesn't have "Tylko rozszerzenie" badge
            if (allInstancesSelected && !hasPartialOnlyBadge) {
                summary.commissionsToCancel.push(commissionData);
            } else {
                summary.transfersToCancel.push(commissionData);
            }
        }
    }

    // Group and sum transfers by source magazine with commission tracking
    const transfersBySource = {};
    summary.allTransfers.forEach(transfer => {
        transfer.sources.forEach(sourceWithQuantity => {
            // Parse "Magazine Name (quantity)" format
            const match = sourceWithQuantity.match(/(.+?)\s*\((\d+(?:\.\d+)?)\)/);
            if (match) {
                const sourceName = match[1].trim();
                const quantity = parseFloat(match[2]);

                if (!transfersBySource[sourceName]) {
                    transfersBySource[sourceName] = {
                        totalQuantity: 0,
                        components: {},
                        destination: transfer.destination
                    };
                }

                transfersBySource[sourceName].totalQuantity += quantity;

                if (!transfersBySource[sourceName].components[transfer.componentName]) {
                    transfersBySource[sourceName].components[transfer.componentName] = {
                        quantity: 0,
                        commissionDetails: []
                    };
                }

                transfersBySource[sourceName].components[transfer.componentName].quantity += quantity;
                transfersBySource[sourceName].components[transfer.componentName].commissionDetails.push({
                    deviceName: transfer.deviceName,
                    quantity: quantity,
                    commissionId: transfer.commissionId
                });
            }
        });
    });

    // Generate HTML
    let summaryHtml = '';

    if (!summary.hasSelections) {
        summaryHtml = '<div class="text-muted text-center">Nie wybrano żadnych elementów do anulacji</div>';
    } else {
        if (summary.commissionsToCancel.length > 0) {
            summaryHtml += '<div class="mb-3">';
            summaryHtml += '<h6 class="text-danger"><i class="fas fa-times-circle"></i> Anulowane zlecenia:</h6>';
            summaryHtml += '<table class="table table-sm table-bordered">';
            summaryHtml += '<thead class="thead-light">';
            summaryHtml += '<tr><th>ID</th><th>Zlecenie</th><th>Ilość</th><th>Wyprodukowano</th><th>Zwrócono</th><th>Odbiorcy</th><th>Transfery</th></tr>';
            summaryHtml += '</thead><tbody>';
            summary.commissionsToCancel.forEach(commission => {
                summaryHtml += '<tr>';
                summaryHtml += `<td>${commission.id}</td>`;
                summaryHtml += `<td><strong>${commission.deviceName}</strong></td>`;
                summaryHtml += `<td>${commission.quantity}</td>`;
                summaryHtml += `<td>${commission.quantityProduced}</td>`;
                summaryHtml += `<td>${commission.quantityReturned}</td>`;
                summaryHtml += `<td><small>${commission.receivers}</small></td>`;
                summaryHtml += `<td>${commission.selectedTransfers}/${commission.totalTransfers}</td>`;
                summaryHtml += '</tr>';
            });
            summaryHtml += '</tbody></table></div>';
        }

        if (summary.transfersToCancel.length > 0) {
            summaryHtml += '<div class="mb-3">';
            summaryHtml += '<h6 class="text-warning"><i class="fas fa-exchange-alt"></i> Anulowane transfery:</h6>';
            summaryHtml += '<table class="table table-sm table-bordered">';
            summaryHtml += '<thead class="thead-light">';
            summaryHtml += '<tr><th>ID</th><th>Zlecenie</th><th>Ilość</th><th>Wyprodukowano</th><th>Zwrócono</th><th>Odbiorcy</th><th>Transfery</th></tr>';
            summaryHtml += '</thead><tbody>';
            summary.transfersToCancel.forEach(commission => {
                summaryHtml += '<tr>';
                summaryHtml += `<td>${commission.id}</td>`;
                summaryHtml += `<td><strong>${commission.deviceName}</strong></td>`;
                summaryHtml += `<td>${commission.quantity}</td>`;
                summaryHtml += `<td>${commission.quantityProduced}</td>`;
                summaryHtml += `<td>${commission.quantityReturned}</td>`;
                summaryHtml += `<td><small>${commission.receivers}</small></td>`;
                summaryHtml += `<td>${commission.selectedTransfers}/${commission.totalTransfers}</td>`;
                summaryHtml += '</tr>';
            });
            summaryHtml += '</tbody></table></div>';
        }

        // Add detailed rollback transfers section
        if (Object.keys(transfersBySource).length > 0) {
            // First, collect all commission IDs that need component data
            const commissionIds = new Set();
            summary.allTransfers.forEach(transfer => {
                if (transfer.commissionId && transfer.commissionId !== 'manual') {
                    commissionIds.add(transfer.commissionId);
                }
            });

            // Fetch component usage data from backend
            let componentUsageData = {};
            if (commissionIds.size > 0) {
                $.ajax({
                    type: "POST",
                    url: COMPONENTS_PATH + "/commissions/get-commission-components-usage.php",
                    data: { commissionIds: Array.from(commissionIds) },
                    async: false,
                    success: function(response) {
                        const result = JSON.parse(response);
                        if (result.success) {
                            componentUsageData = result.data;
                        }
                    }
                });
            }

            // First pass: collect ALL components across ALL sources, grouped by component+commission
            const allComponentsByCommission = {};

            for (const [srcName, srcData] of Object.entries(transfersBySource)) {
                summary.allTransfers.forEach(transfer => {
                    const transferSource = transfer.sources.find(s => {
                        const match = s.match(/(.+?)\s*\(/);
                        return match && match[1].trim() === srcName;
                    });

                    if (!transferSource) return;

                    const quantityMatch = transferSource.match(/\((\d+(?:\.\d+)?)\)/);
                    const transferredQty = quantityMatch ? parseFloat(quantityMatch[1]) : 0;

                    const key = `${transfer.componentName}_${transfer.commissionId}`;
                    if (!allComponentsByCommission[key]) {
                        allComponentsByCommission[key] = {
                            componentName: transfer.componentName,
                            commissionId: transfer.commissionId,
                            deviceName: transfer.deviceName,
                            destination: transfer.destination, // Store the destination
                            sourceQuantities: {}
                        };
                    }

                    if (!allComponentsByCommission[key].sourceQuantities[srcName]) {
                        allComponentsByCommission[key].sourceQuantities[srcName] = 0;
                    }
                    allComponentsByCommission[key].sourceQuantities[srcName] += transferredQty;
                });
            }

            // Calculate usage distribution for ALL components once
            const usageDistribution = {};

            Object.entries(allComponentsByCommission).forEach(([key, item]) => {
                let totalUsedForComponent = 0;

                if (componentUsageData[item.commissionId]) {
                    const commissionData = componentUsageData[item.commissionId];
                    for (const [componentKey, comp] of Object.entries(commissionData.components)) {
                        if (comp.componentName === item.componentName) {
                            totalUsedForComponent = parseFloat(comp.totalUsed) || 0;
                            break;
                        }
                    }
                }

                // Get the main warehouse ID for this commission
                const commissionGroupData = currentCancellationData.commissionGroups
                    .flatMap(g => g.allCommissions)
                    .find(c => c.commissionId === item.commissionId);

                const mainWarehouseId = commissionGroupData?.magazineFrom;
                console.log(`Commission ${item.commissionId} main warehouse ID: ${mainWarehouseId}`);
                // Build a map of source names to their warehouse IDs
                const sourceNameToId = {};

                summary.allTransfers.forEach(transfer => {
                    if (transfer.commissionId === item.commissionId && transfer.componentName === item.componentName) {
                        // Use sourceIds from the transfer data
                        if (transfer.sourceIds && Array.isArray(transfer.sourceIds)) {
                            transfer.sourceIds.forEach(sourceInfo => {
                                sourceNameToId[sourceInfo.name] = sourceInfo.id;
                                console.log(`Mapping source "${sourceInfo.name}" to ID ${sourceInfo.id}`);
                            });
                        }
                    }
                });

                const sources = Object.entries(item.sourceQuantities).map(([name, quantity]) => {
                    const warehouseId = sourceNameToId[name];
                    const isMain = warehouseId !== undefined && warehouseId === mainWarehouseId;

                    console.log(`Source "${name}": warehouseId=${warehouseId}, mainWarehouseId=${mainWarehouseId}, isMain=${isMain}`);

                    return {
                        name: name,
                        id: warehouseId,
                        quantity: quantity,
                        isMain: isMain
                    };
                });

                console.log(`Sources for ${item.componentName}:`, sources);

                // Sort: non-main first
                sources.sort((a, b) => {
                    if (a.isMain === b.isMain) return 0;
                    return a.isMain ? 1 : -1;  // Non-main sources first
                });

                // Distribute usage across sources (non-main first)
                let remainingUsage = totalUsedForComponent;
                const usageBySource = {};

                sources.forEach(source => {
                    if (remainingUsage <= 0) {
                        usageBySource[source.name] = 0;
                    } else if (remainingUsage >= source.quantity) {
                        usageBySource[source.name] = source.quantity;
                        remainingUsage -= source.quantity;
                    } else {
                        usageBySource[source.name] = remainingUsage;
                        remainingUsage = 0;
                    }
                });

                // Apply manual adjustments AFTER initial distribution
                sources.forEach(source => {
                    const adjustmentKey = `${key}_${source.name}`;
                    const adjustment = currentCancellationData.manualUsageAdjustments[adjustmentKey] || 0;

                    console.log(`Checking adjustment for ${adjustmentKey}: ${adjustment}`);

                    if (adjustment !== 0) {
                        // Apply adjustment to this source
                        const currentUsage = usageBySource[source.name] || 0;
                        const newUsage = currentUsage + adjustment;

                        // Clamp between 0 and transferred quantity
                        const clampedUsage = Math.max(0, Math.min(source.quantity, newUsage));
                        const actualAdjustment = clampedUsage - currentUsage;

                        usageBySource[source.name] = clampedUsage;

                        console.log(`Source ${source.name}: currentUsage=${currentUsage}, adjustment=${adjustment}, newUsage=${clampedUsage}, actualAdjustment=${actualAdjustment}`);

                        // Apply opposite adjustment to main warehouse to keep total consistent
                        const mainSource = sources.find(s => s.isMain);
                        if (mainSource) {
                            const mainCurrentUsage = usageBySource[mainSource.name] || 0;
                            const mainNewUsage = mainCurrentUsage - actualAdjustment;
                            usageBySource[mainSource.name] = Math.max(0, Math.min(mainSource.quantity, mainNewUsage));

                            console.log(`Main warehouse ${mainSource.name}: was=${mainCurrentUsage}, now=${usageBySource[mainSource.name]}`);
                        }

                        console.log(`Applied adjustment ${actualAdjustment} to ${source.name}, compensated in main warehouse`);
                    }
                });

                // Final logging
                sources.forEach(source => {
                    const used = usageBySource[source.name] || 0;
                    const remaining = source.quantity - used;
                    console.log(`  → ${source.name} (isMain: ${source.isMain}): transferred=${source.quantity}, used=${used}, remaining=${remaining}`);
                });

                usageDistribution[key] = usageBySource;
            });

            const rollbackCollapseId = 'rollbackDetailsCollapse';
            summaryHtml += '<div class="mb-3">';
            summaryHtml += '<button class="btn btn-link btn-sm p-0 mb-2" type="button" ';
            summaryHtml += `data-toggle="collapse" data-target="#${rollbackCollapseId}" `;
            summaryHtml += 'aria-expanded="false">';
            summaryHtml += '<i class="fas fa-chevron-right"></i> Szczegóły transferów do cofnięcia';
            summaryHtml += `<span class="badge badge-secondary ml-2">${Object.keys(transfersBySource).length} magazynów</span>`;
            summaryHtml += '</button>';

            summaryHtml += `<div class="collapse" id="${rollbackCollapseId}">`;
            summaryHtml += '<div class="card card-body p-3" style="background-color: #f8f9fa;">';

            // Now render the tables with the pre-calculated distribution
            for (const [sourceName, sourceData] of Object.entries(transfersBySource)) {
                summaryHtml += '<div class="mb-3">';
                summaryHtml += `<h6 class="text-info mb-2"><i class="fas fa-warehouse"></i> ${sourceName}</h6>`;
                summaryHtml += '<table class="table table-sm table-bordered mb-2">';
                summaryHtml += '<thead class="thead-light">';
                summaryHtml += '<tr><th>Komponent</th><th>Łączna ilość</th><th>Zlecenie</th></tr>';
                summaryHtml += '</thead><tbody>';

                // Iterate through components for THIS source
                Object.entries(allComponentsByCommission).forEach(([key, item]) => {
                    // Skip if this component doesn't have transfers from this source
                    if (!item.sourceQuantities[sourceName]) {
                        return;
                    }

                    const transferredQty = item.sourceQuantities[sourceName];
                    const usedQty = usageDistribution[key][sourceName] || 0;
                    const remaining = transferredQty - usedQty;

                    // Get the commission's main warehouse ID to determine if THIS source is main
                    const commissionGroupData = currentCancellationData.commissionGroups
                        .flatMap(g => g.allCommissions)
                        .find(c => c.commissionId === item.commissionId);

                    const mainWarehouseId = commissionGroupData?.magazineFrom;

                    // Find the warehouse ID for this source name
                    let sourceWarehouseId;
                    summary.allTransfers.forEach(transfer => {
                        if (transfer.commissionId === item.commissionId && transfer.componentName === item.componentName) {
                            if (transfer.sourceIds && Array.isArray(transfer.sourceIds)) {
                                const sourceInfo = transfer.sourceIds.find(s => s.name === sourceName);
                                if (sourceInfo) {
                                    sourceWarehouseId = sourceInfo.id;
                                }
                            }
                        }
                    });

                    const isMainSource = sourceWarehouseId !== undefined && sourceWarehouseId === mainWarehouseId;

                    // Skip if remaining is exactly 0 AND it's the main warehouse
                    // Non-main warehouses with 0 remaining should be shown
                    if (remaining === 0 && isMainSource) {
                        return;
                    }

                    // Track negative values
                    if (remaining < 0) {
                        hasNegativeRemaining = true;
                    }

                    // Determine badge color
                    const badgeClass = remaining < 0 ? 'badge-danger' : (remaining === 0 ? 'badge-secondary' : 'badge-primary');

                    // Format numbers
                    const formatNumber = (num) => {
                        return parseFloat(num.toFixed(5)).toString();
                    };

                    summaryHtml += '<tr>';
                    summaryHtml += `<td>${item.componentName}</td>`;

                    // Build the quantity cell with adjustment buttons for non-main warehouses
                    let quantityCell = `<td>${formatNumber(transferredQty)} - ${formatNumber(usedQty)} = <span class="badge ${badgeClass}">${formatNumber(remaining)}</span>`;

                    // Add +/- buttons for non-main warehouses only
                    if (!isMainSource) {
                        quantityCell += `
                            <div class="btn-group btn-group-sm ml-2" role="group">
                                <button type="button" class="btn btn-sm btn-outline-secondary adjust-usage" 
                                        data-component-key="${key}" 
                                        data-source-name="${sourceName}"
                                        data-commission-id="${item.commissionId}"
                                        data-action="decrease"
                                        ${remaining <= 0 ? 'disabled' : ''}>
                                    −
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary adjust-usage" 
                                        data-component-key="${key}" 
                                        data-source-name="${sourceName}"
                                        data-commission-id="${item.commissionId}"
                                        data-action="increase"
                                        ${usedQty <= 0 ? 'disabled' : ''}>
                                    +
                                </button>
                            </div>
                                <button type="button" class="btn btn-sm btn-outline-primary adjust-usage" 
                                        data-component-key="${key}" 
                                        data-source-name="${sourceName}"
                                        data-commission-id="${item.commissionId}"
                                        data-action="max"
                                        data-transferred-qty="${transferredQty}"
                                        ${remaining >= transferredQty ? 'disabled' : ''}>
                                    Max
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger adjust-usage" 
                                        data-component-key="${key}" 
                                        data-source-name="${sourceName}"
                                        data-commission-id="${item.commissionId}"
                                        data-action="zero"
                                        ${remaining <= 0 ? 'disabled' : ''}>
                                    0
                                </button>
                        `;
                    }

                    quantityCell += '</td>';
                    summaryHtml += quantityCell;
                    summaryHtml += `<td><small><strong>${item.deviceName}</strong></small></td>`;
                    summaryHtml += '</tr>';
                });

                summaryHtml += '</tbody></table>';
                summaryHtml += `<small class="text-muted">Komponenty cofnięte do <strong>${sourceName}</strong></small>`;
                summaryHtml += '</div>';
            }

            summaryHtml += '</div></div></div>';
        }

        // Add rollback information
        summaryHtml += '<div class="mt-3 pt-2 border-top">';
        summaryHtml += '<small class="text-muted">';
        summaryHtml += '<i class="fas fa-info-circle"></i> ';
        summaryHtml += 'Anulacja spowoduje cofnięcie transferów komponentów do magazynów źródłowych. ';
        summaryHtml += 'Operacja jest nieodwracalna.';
        summaryHtml += '</small>';
        summaryHtml += '</div>';
    }
    console.log('Final summaryHtml contains buttons:', summaryHtml.includes('adjust-usage'));
    console.log('Sample of HTML:', summaryHtml.substring(summaryHtml.indexOf('adjust-usage') - 100, summaryHtml.indexOf('adjust-usage') + 200));

    const wasCollapsed = !$('#rollbackDetailsCollapse').hasClass('show');
    // Update summary container
    $('#summaryContent').html(summaryHtml);

    if (summary.hasSelections) {
        $('#cancellationSummary').show();

        // Restore collapse state AFTER HTML is inserted
        if (!wasCollapsed) {
            $('#rollbackDetailsCollapse').collapse('show');
        }

        // Add collapse event handlers for the chevron rotation
        $(`#rollbackDetailsCollapse`).on('shown.bs.collapse', function() {
            $(this).prev().find('.fa-chevron-right').removeClass('fa-chevron-right').addClass('fa-chevron-down');
        });

        $(`#rollbackDetailsCollapse`).on('hidden.bs.collapse', function() {
            $(this).prev().find('.fa-chevron-down').removeClass('fa-chevron-down').addClass('fa-chevron-right');
        });

        if (hasNegativeRemaining) {
            $("#confirmCancellation").prop('disabled', true);

            // Add warning message at the TOP of summary
            if (!$('#negativeWarning').length) {
                $('#summaryContent').prepend(`
                <div id="negativeWarning" class="alert alert-danger mb-3">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Nie można kontynuować:</strong> Wykryto komponenty z ujemną ilością do zwrotu (czerwone wartości). 
                    To oznacza, że użyto więcej komponentów niż przetransferowano. Sprawdź dane i skoryguj przed anulacją.
                </div>
            `);
            }
        } else {
            $('#negativeWarning').remove();
        }
    } else {
        $('#cancellationSummary').hide();
    }
}

function updateRollbackOptions() {
    if (currentCancellationData.selectedGroups.size === 0) {
        $("#rollbackOptionsContainer").hide();
        return;
    }

    // Load rollback distribution for selected groups
    const selectedTransferIds = Array.from(currentCancellationData.selectedTransferIds);

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/commissions/get-rollback-distribution.php",
        data: {
            commissionId: currentCancellationData.commissionId,
            transferIds: selectedTransferIds
        },
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                setupRollbackDistribution(result.data);
            }
        }
    });
}

function setupRollbackDistribution(data) {
    const tbody = $("#rollbackDistributionTable");
    tbody.empty();

    if (!data || Object.keys(data).length === 0) {
        tbody.append('<tr><td colspan="3" class="text-center text-muted">Brak komponentów do cofnięcia</td></tr>');
        return;
    }

    for (const [componentKey, componentData] of Object.entries(data)) {
        const component = componentData.component;
        const sources = componentData.sources;
        const totalQuantity = componentData.totalQuantity;
        const producedQuantity = componentData.producedQuantity || 0;
        const availableForRollback = Math.max(0, totalQuantity - producedQuantity);

        if (availableForRollback === 0) continue;

        // Create rollback distribution row
        let distributionHtml = '<div class="rollback-sources">';

        sources.forEach((source, index) => {
            const proportion = source.quantity / totalQuantity;
            const defaultRollback = Math.round(availableForRollback * proportion);

            distributionHtml += `
                <div class="input-group input-group-sm mb-1">
                    <div class="input-group-prepend">
                        <span class="input-group-text" style="min-width: 120px;">${source.magazineName}</span>
                    </div>
                    <input type="number" class="form-control rollback-quantity" 
                           data-component="${componentKey}" 
                           data-source="${source.magazineId}"
                           data-max="${source.quantity}"
                           value="${defaultRollback}" 
                           min="0" max="${availableForRollback}">
                </div>`;
        });

        distributionHtml += '</div>';

        const row = `
            <tr>
                <td>${component.name}<br><small class="text-muted">${component.description || ''}</small></td>
                <td>
                    <span class="badge badge-primary">${availableForRollback}</span>
                    ${producedQuantity > 0 ? `<br><small class="text-muted">Wyprodukowano: ${producedQuantity}</small>` : ''}
                </td>
                <td>${distributionHtml}</td>
            </tr>`;

        tbody.append(row);
    }

    // Add event listeners for quantity validation
    $(".rollback-quantity").on('input', validateRollbackDistribution);

    // Show rollback container
    $("#rollbackOptionsContainer").show();
}

// Enable/disable rollback
$("#enableRollback").change(function() {
    const enabled = $(this).is(':checked');
    $("#rollbackDistributionContainer").toggle(enabled);
});

function validateRollbackDistribution() {
    let isValid = true;
    const componentTotals = {};

    // Group quantities by component
    $(".rollback-quantity").each(function() {
        const $input = $(this);
        const component = $input.data('component');
        const quantity = parseInt($input.val()) || 0;

        if (!componentTotals[component]) {
            componentTotals[component] = { total: 0, required: 0 };
        }

        componentTotals[component].total += quantity;
    });

    // Validate each component (this would need the original data structure)
    // For now, just check if any input is invalid
    $(".rollback-quantity").each(function() {
        const $input = $(this);
        const quantity = parseInt($input.val()) || 0;
        const max = parseInt($input.attr('max')) || 0;

        if (quantity < 0 || quantity > max) {
            isValid = false;
            $input.addClass('is-invalid');
        } else {
            $input.removeClass('is-invalid');
        }
    });

    $("#confirmCancellation").prop('disabled', !isValid || currentCancellationData.selectedGroups.size === 0);
}

function getRollbackDistribution() {
    const distribution = {};

    $(".rollback-quantity").each(function() {
        const $input = $(this);
        const component = $input.data('component');
        const sourceId = $input.data('source');
        const quantity = parseInt($input.val()) || 0;

        if (!distribution[component]) {
            distribution[component] = [];
        }

        if (quantity > 0) {
            distribution[component].push({
                sourceId: sourceId,
                quantity: quantity
            });
        }
    });

    return distribution;
}

$("#confirmCancellation").click(function() {
    const data = {
        commissionId: currentCancellationData.commissionId,
        selectedCommissions: Array.from(currentCancellationData.selectedCommissions),
        selectedTransfers: currentCancellationData.selectedTransfers
    };

    performEnhancedCancellation(data);
});


function performEnhancedCancellation(data) {
    $("#confirmCancellation").prop('disabled', true).text('Anulowanie...');

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/commissions/cancel-commission-enhanced.php",
        data: data,
        success: function(response) {
            const result = JSON.parse(response);

            $("#cancelCommissionModal").modal("hide");

            if (result.success) {
                showAlert("Anulacja została wykonana pomyślnie", "success");
                renderCommissions();
            } else {
                showAlert("Błąd podczas anulacji: " + result.message, "danger");
            }
        },
        error: function() {
            showAlert("Błąd podczas anulacji zlecenia", "danger");
        },
        complete: function() {
            $("#confirmCancellation").prop('disabled', false).text('Potwierdź anulację');
        }
    });
}

function showAlert(message, type) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>`;

    $("#ajaxResult").append(alertHtml);

    if (type === 'success') {
        setTimeout(function() {
            $(".alert-success").alert('close');
        }, 3000);
    }
}

// Handle usage adjustment buttons
$('body').on('click', '.adjust-usage', function(e) {
    e.preventDefault();

    const $btn = $(this);
    const componentKey = $btn.data('component-key');
    const sourceName = $btn.data('source-name');
    const commissionId = $btn.data('commission-id');
    const action = $btn.data('action');

    const adjustmentKey = `${componentKey}_${sourceName}`;

    // Initialize adjustment if not exists
    if (!currentCancellationData.manualUsageAdjustments[adjustmentKey]) {
        currentCancellationData.manualUsageAdjustments[adjustmentKey] = 0;
    }

    // Adjust by 1 (or 0.1 for decimal components if needed)
    const adjustmentAmount = 1;

    if (action === 'increase') {
        // Decrease usage from this source (increase remaining)
        currentCancellationData.manualUsageAdjustments[adjustmentKey] -= adjustmentAmount;
    } else if (action === 'decrease') {
        // Increase usage from this source (decrease remaining)
        currentCancellationData.manualUsageAdjustments[adjustmentKey] += adjustmentAmount;
    } else if (action === 'max') {
        // Set usage to 0 (maximize remaining - all goes back)
        const transferredQty = parseFloat($btn.data('transferred-qty'));

        // Set a very large negative adjustment that will be clamped to 0 usage
        currentCancellationData.manualUsageAdjustments[adjustmentKey] = -transferredQty;
    } else if (action === 'zero') {
        // Set usage to transferred amount (zero remaining - nothing goes back)
        const transferredQty = parseFloat($btn.data('transferred-qty'));

        // Set a very large positive adjustment that will be clamped to full usage
        currentCancellationData.manualUsageAdjustments[adjustmentKey] = transferredQty;
    }

    console.log(`Adjustment for ${adjustmentKey}: ${currentCancellationData.manualUsageAdjustments[adjustmentKey]}`);

    // Regenerate the summary with new adjustments
    generateCancellationSummary();
});

// Reset modal when closed
$("#cancelCommissionModal").on('hidden.bs.modal', function() {
    currentCancellationData = {
        commissionId: null,
        commissionGroups: [],
        selectedGroups: new Set(),
        manualUsageAdjustments: {}
    };
    $("#groupsList").empty();
    $("#rollbackDistributionTable").empty();
    $("#enableRollback").prop('checked', true);
    $("#cancelEntireGroups").prop('checked', false);
    $("#fullCancellationWarning, #multiGroupWarning").hide();

    // Reset visual indicators
    $('.commission-will-be-cancelled').removeClass('commission-will-be-cancelled');
    $('.commission-cancellation-badge').remove();

    $('#cancellationSummary').hide();
    $('#summaryContent').empty();
});