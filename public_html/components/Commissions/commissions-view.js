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

function generateUserSelect(submagId)
{
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

function editCommission(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/commissions/edit-commission.php",
        data: data,
        async: true,
        success: function(response)
        {
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

$('body').on('click', '.cancelCommission', function() {
    let id = $(this).attr("data-id");
    $("#cancel_commission_id").val(id);
    showModalWithLoading();
    loadCommissionCancelDetails(id);
});

function showModalWithLoading() {
    // Show loading content in modal
    $("#commission_details_display").html('<div class="text-center"><i class="bi bi-hourglass-split"></i> Ładowanie szczegółów...</div>');
    $("#transferred_items_display").html('<div class="text-center"><i class="bi bi-hourglass-split"></i> Ładowanie przedmiotów...</div>');

    // Clear and hide the unreturned products section
    $("#unreturned_products_display").html('');
    $("#unreturned_products_section").hide();

    // Reset form options to default values
    $('input[name="rollbackOption"][value="none"]').prop('checked', true);
    $('input[name="unreturnedOption"][value="transfer"]').prop('checked', true);

    // Hide any warning sections
    $("#rollback_details, #delete_warning, #unreturned_remove_warning").hide();

    // Disable submit button until data loads
    $("#cancelCommissionSubmit").prop('disabled', true);

    $("#cancelCommissionModal").modal("show");
}

$("#cancelCommissionForm").submit(function(e) {
    e.preventDefault();
    let commissionId = $("#cancel_commission_id").val();
    let rollbackOption = $('input[name="rollbackOption"]:checked').val();
    let unreturnedOption = $('input[name="unreturnedOption"]:checked').val();

    const data = {
        id: commissionId,
        rollbackOption: rollbackOption,
        unreturnedOption: unreturnedOption
    };

    cancelCommission(data);
    $("#cancelCommissionModal").modal("hide");
    renderCommissions();
});

$('input[name="rollbackOption"]').change(function() {
    const value = $(this).val();
    const commissionId = $("#cancel_commission_id").val();

    if (value === 'none') {
        $("#rollback_details, #delete_warning").hide();
    } else if (value === 'delete') {
        $("#rollback_details").show();
        $("#delete_warning").show();
        updateRollbackSummary(value, commissionId);
    } else {
        $("#rollback_details").show();
        $("#delete_warning").hide();
        updateRollbackSummary(value, commissionId);
    }
});

function displayUnreturnedProducts(commission) {
    const html = `
        <div class="table-responsive">
            <table class="table table-sm table-borderless">
                <tbody>
                    <tr>
                        <td class="text-muted" style="width: 120px;"><strong>Produkt:</strong></td>
                        <td>${commission.deviceName} (${commission.version})</td>
                    </tr>
                    <tr>
                        <td class="text-muted"><strong>Ilość:</strong></td>
                        <td><span class="badge badge-warning">${commission.unreturnedProducts} szt.</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><strong>Lokalizacja:</strong></td>
                        <td><strong>${commission.magazineTo}</strong> <small class="text-muted">(magazyn podwykonawcy)</small></td>
                    </tr>
                </tbody>
            </table>
        </div>
    `;
    $("#unreturned_products_display").html(html);
}

$('input[name="unreturnedOption"]').change(function() {
    const value = $(this).val();

    if (value === 'remove') {
        $("#unreturned_remove_warning").show();
    } else {
        $("#unreturned_remove_warning").hide();
    }
});

function loadCommissionCancelDetails(commissionId) {
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/commissions/get-commission-cancel-details.php",
        data: {id: commissionId},
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                displayCommissionDetails(result.commission);
                displayTransferredItems(result.transferredItems);
                updateRollbackOptions(result);
            } else {
                $("#commission_details_display").html('<div class="text-danger">Błąd ładowania szczegółów zlecenia</div>');
                $("#transferred_items_display").html('<div class="text-danger">Błąd ładowania przedmiotów</div>');
            }
            $("#cancelCommissionSubmit").prop('disabled', false);
        },
        error: function() {
            $("#commission_details_display").html('<div class="text-danger">Błąd połączenia</div>');
            $("#transferred_items_display").html('<div class="text-danger">Błąd połączenia</div>');
        }
    });
}

function updateRollbackOptions(result) {
    // Enable/disable rollback options based on transferred items
    if (result.transferredItems && result.transferredItems.length > 0) {
        // Check if there are any remaining (unused) items
        const hasRemainingItems = result.transferredItems.some(item =>
            item.remainingQuantity > 0
        );

        // Enable/disable the "remaining" and "delete" options based on available items
        $('input[name="rollbackOption"][value="remaining"]').prop('disabled', !hasRemainingItems);
        $('input[name="rollbackOption"][value="delete"]').prop('disabled', !hasRemainingItems);

        // If no remaining items, automatically select "none"
        if (!hasRemainingItems) {
            $('input[name="rollbackOption"][value="none"]').prop('checked', true);
        }
    } else {
        // If no transferred items at all, disable rollback options
        $('input[name="rollbackOption"][value="remaining"], input[name="rollbackOption"][value="delete"]').prop('disabled', true);
        $('input[name="rollbackOption"][value="none"]').prop('checked', true);
    }
}

function displayCommissionDetails(commission) {
    const html = `
        <div class="row">
            <div class="col-md-6">
                <strong>Urządzenie:</strong> ${commission.deviceName}<br>
                <strong>Opis:</strong> ${commission.deviceDescription}<br>
                <strong>Wersja:</strong> ${commission.version}
            </div>
            <div class="col-md-6">
                <strong>Z magazynu:</strong> ${commission.magazineFrom}<br>
                <strong>Do magazynu:</strong> ${commission.magazineTo}<br>
                <strong>Zlecono:</strong> ${commission.quantity} szt.
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-12">
                <strong>Wyprodukowano:</strong> ${commission.quantityProduced} szt. |
                <strong>Dostarczono:</strong> ${commission.quantityReturned} szt. |
                <strong>Pozostało:</strong> ${commission.quantity - commission.quantityReturned} szt.
            </div>
        </div>
    `;
    $("#commission_details_display").html(html);

    // Show/hide unreturned products section
    if (commission.unreturnedProducts > 0) {
        displayUnreturnedProducts(commission);
        $("#unreturned_products_section").show();
    } else {
        $("#unreturned_products_section").hide();
    }
}


function displayTransferredItems(items) {
    if (items.length === 0) {
        $("#transferred_items_display").html('<div class="text-muted">Brak przeniesionych przedmiotów do wyświetlenia</div>');
        // Disable rollback options if no items
        $('input[name="rollbackOption"][value="remaining"], input[name="rollbackOption"][value="all"]').prop('disabled', true);
        return;
    }

    let html = '<div class="table-responsive"><table class="table table-sm table-striped">';
    html += `
        <thead>
            <tr>
                <th>Typ</th>
                <th>Przedmiot</th>
                <th>Ilość</th>
                <th>Pozostała ilość</th>
            </tr>
        </thead>
        <tbody>
    `;

    items.forEach(item => {
        const typeLabels = {
            'parts': 'Części',
            'sku': 'SKU',
            'tht': 'THT',
            'smd': 'SMD'
        };

        html += `
            <tr class="${item.isReturned ? 'table-success' : ''}">
                <td><span class="badge badge-secondary">${typeLabels[item.itemType]}</span></td>
                <td>${item.itemName}</td>
                <td>${item.quantity}</td>
                <td>${item.remainingQuantity}</td>
            </tr>
        `;
    });

    html += '</tbody></table></div>';

    $("#transferred_items_display").html(html);

    // Enable/disable rollback options based on available items
    const totalTransferred = items.reduce((sum, item) => sum + parseFloat(item.quantity), 0);
    const totalReturned = items.filter(item => item.isReturned).reduce((sum, item) => sum + parseFloat(item.quantity), 0);
    const totalRemaining = totalTransferred - totalReturned;

    $('input[name="rollbackOption"][value="remaining"]').prop('disabled', totalRemaining === 0);
    $('input[name="rollbackOption"][value="all"]').prop('disabled', totalTransferred === 0);
}

function updateRollbackSummary(rollbackType, commissionId) {
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/commissions/get-rollback-preview.php",
        data: {id: commissionId, rollbackType: rollbackType},
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                $("#rollback_summary").html(result.summary);
            }
        }
    });
}

function cancelCommission(data) {
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/commissions/cancel-commission.php",
        data: data,
        async: true,
        success: function(response) {
            const result = JSON.parse(response);
            const wasSuccessful = result[0];
            const errorMessage = result[1];
            let resultMessage = wasSuccessful ?
                "Anulowanie zlecenia powiodło się." :
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