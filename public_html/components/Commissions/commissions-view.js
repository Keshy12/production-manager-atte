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
    selectedGroups: new Set()
};

$('body').on('click', '.cancelCommission', function() {
    const commissionId = $(this).attr("data-id");
    currentCancellationData.commissionId = commissionId;
    loadCommissionGroups(commissionId);
});

function loadCommissionGroups(commissionId) {
    $("#cancelModalOverlay").show();

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
                (commission.stateId == 3 ? '<span class="badge badge-success">Zakończone</span>' :
                    '<span class="badge badge-primary">Aktywne</span>');

            const currentBadge = commission.isCurrentCommission ?
                '<span class="badge badge-warning">Bieżące</span>' : '';

            const extensionBadge = commission.isPartialView ?
                '<span class="badge badge-danger">Tylko rozszerzenie</span>' :
                (commission.isExtension ? '<span class="badge badge-info">Wymaga wszystkich rozszerzeń</span>' : '');

            const laminateInfo = commission.laminate ? ` (${commission.laminate})` : '';

            // Build transfers with individual checkboxes
            const transfersHtml = commission.transfers.length > 0 ?
                commission.transfers.map((transfer, transferIndex) => `
                    <tr>
                        <td>
                            <div class="form-check">
                                <input class="form-check-input transfer-checkbox" type="checkbox" 
                                       id="transfer_${group.id}_${index}_${transferIndex}"
                                       data-commission-id="${commission.commissionId || 'manual'}"
                                       data-transfer-id="${commission.transferId}"
                                       data-component="${transfer.componentName}"
                                       ${commission.isCurrentCommission ? 'checked' : ''}>
                                <label class="form-check-label" for="transfer_${group.id}_${index}_${transferIndex}">
                                    <strong>${transfer.componentName}</strong>
                                    ${transfer.componentDescription ? `<br><small class="text-muted">${transfer.componentDescription}</small>` : ''}
                                </label>
                            </div>
                        </td>
                        <td><span class="badge badge-secondary">${transfer.quantity}</span></td>
                        <td><small>${transfer.sources.join(', ')} → ${transfer.destination}</small></td>
                    </tr>
                `).join('') :
                '<tr><td colspan="3" class="text-muted text-center">Brak transferów</td></tr>';

            const commissionTitle = commission.isManualComponents ?
                'Komponenty dodane ręcznie' :
                `${commission.deviceName} lam. ${laminateInfo} ver. ${commission.version}`;

            const transferCollapseId = `transferCollapse_${groupIndex}_${index}`;

            return `
                <div class="commission-container mb-3 ${commission.isExtension ? 'border-left border-danger pl-2' : ''}">
                    <div class="d-flex align-items-center mb-2">
                        <div class="form-check mr-3">
                            <input class="form-check-input commission-checkbox" type="checkbox" 
                                   id="commission_${commission.commissionId || 'manual_' + index}" 
                                   data-commission-id="${commission.commissionId || 'manual'}"
                                   data-transfer-id="${commission.transferId}"
                                   data-is-manual="${commission.isManualComponents || false}"
                                   data-is-extension="${commission.isExtension || false}"
                                   ${commission.isCurrentCommission ? 'checked' : ''}>
                            <label class="form-check-label" for="commission_${commission.commissionId || 'manual_' + index}">
                                <strong>${commissionTitle}</strong>
                            </label>
                        </div>
                        <div class="flex-grow-1">
                            ${currentBadge} ${statusBadge} ${extensionBadge}
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

        // Count items in group for indicator
        const itemCount = group.allCommissions.length;
        const multipleItemsIndicator = itemCount > 1 ?
            `<span class="badge badge-secondary ml-2">${itemCount} pozycji</span>` : '';

        const groupHtml = `
            <div class="card mb-3">
                <div class="card-header" data-toggle="collapse" data-target="#groupCollapse_${groupIndex}" 
                     style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-chevron-right mr-2"></i>
                            <strong>Transfer #${group.id}</strong>
                            <small class="text-muted ml-2">${group.timestamp}</small>
                            ${multipleItemsIndicator}
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

    // Add event listeners for commission and transfer checkboxes
    $(".commission-checkbox").change(function() {
        const isChecked = $(this).is(':checked');
        const commissionContainer = $(this).closest('.commission-container');

        // Auto-select/deselect all transfers for this commission
        commissionContainer.find('.transfer-checkbox').prop('checked', isChecked);

        updateSelection();
    });

    $(".transfer-checkbox").change(function() {
        // Check if all transfers for this commission are selected
        const commissionContainer = $(this).closest('.commission-container');
        const commissionCheckbox = commissionContainer.find('.commission-checkbox');
        const allTransferCheckboxes = commissionContainer.find('.transfer-checkbox');
        const checkedTransferCheckboxes = commissionContainer.find('.transfer-checkbox:checked');

        if (checkedTransferCheckboxes.length === 0) {
            commissionCheckbox.prop('checked', false);
            commissionCheckbox.prop('indeterminate', false);
        } else if (checkedTransferCheckboxes.length === allTransferCheckboxes.length) {
            commissionCheckbox.prop('checked', true);
            commissionCheckbox.prop('indeterminate', false);
        } else {
            commissionCheckbox.prop('checked', false);
            commissionCheckbox.prop('indeterminate', true);
        }

        updateSelection();
    });

    // Auto-expand the group containing the current commission
    $('.commission-checkbox:checked').closest('.collapse').addClass('show')
        .prev('.card-header').find('.fa-chevron-right').removeClass('fa-chevron-right').addClass('fa-chevron-down');

    // Trigger initial update
    updateSelection();
}

function updateSelection() {
    const selectedCommissions = new Set();
    const selectedTransfers = [];

    $(".commission-checkbox:checked, .commission-checkbox:indeterminate").each(function() {
        const commissionId = $(this).data('commission-id');
        const transferId = $(this).data('transfer-id');
        selectedCommissions.add(commissionId);
    });

    $(".transfer-checkbox:checked").each(function() {
        selectedTransfers.push({
            commissionId: $(this).data('commission-id'),
            transferId: $(this).data('transfer-id'),
            component: $(this).data('component')
        });
    });

    // Enable/disable confirm button
    $("#confirmCancellation").prop('disabled', selectedTransfers.length === 0);

    // Store selection data
    currentCancellationData.selectedCommissions = selectedCommissions;
    currentCancellationData.selectedTransfers = selectedTransfers;
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

// Reset modal when closed
$("#cancelCommissionModal").on('hidden.bs.modal', function() {
    currentCancellationData = {
        commissionId: null,
        commissionGroups: [],
        selectedGroups: new Set()
    };
    $("#groupsList").empty();
    $("#rollbackDistributionTable").empty();
    $("#enableRollback").prop('checked', true);
    $("#cancelEntireGroups").prop('checked', false);
    $("#fullCancellationWarning, #multiGroupWarning").hide();
});