$(document).ready(function(){
    $("[data-toggle=popover]").popover();
    loadCommissions();
});

let commissionCardTemplate = null;

function loadCommissions() {
    if (!commissionCardTemplate) {
        commissionCardTemplate = $('script[data-template="commissionCard"]').text().split(/\$\{(.+?)\}/g);
    }

    const grouped = $('#groupCommissions').prop('checked');

    $('#commissionsSpinner').show();
    $('#commissionsContainer').empty();

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/index/get-active-commissions.php",
        data: {
            groupTogether: grouped
        },
        success: function(response) {
            let result;
            if (typeof response === 'string') {
                result = JSON.parse(response);
            } else {
                result = response;
            }

            if (result.success) {
                renderCommissions(result.data);
            } else {
                $('#commissionsContainer').html('<div class="alert alert-danger">Błąd: ' + result.error + '</div>');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            $('#commissionsContainer').html('<div class="alert alert-danger">Błąd ładowania zleceń: ' + error + '</div>');
        },
        complete: function() {
            $('#commissionsSpinner').hide();
        }
    });
}

function renderCommissions(commissions) {
    const container = $('#commissionsContainer');
    container.empty();

    if (!commissions || commissions.length === 0) {
        container.html('<div class="text-muted">Brak aktywnych zleceń</div>');
        return;
    }

    commissions.forEach(commission => {
        const cardData = buildCardData(commission.deviceType, commission.valuesToPrint);
        const card = commissionCardTemplate.map((token, i) => {
            return (i % 2) ? cardData[token] : token;
        }).join('');
        container.append(card);
    });

    $("[data-toggle=popover]").popover();
}

function buildCardData(deviceType, values) {
    const isGrouped = values.isGrouped;

    const receiversButton = !isGrouped ? `
        <button type="button"
                style="float: left; ${values.hideButton};"
                class="close receivers" tabindex="0" role="button"
                data-toggle="popover" data-trigger="focus"
                data-html="true"
                data-content="${values.receivers.join('<br>')}"
                data-original-title="Kontraktorzy:">
            <img src="http://${window.config.baseUrl}/public_html/assets/img/index/subcontractors.svg" style="width: 20px;">
        </button>
    ` : '';

    const menuButton = !isGrouped ? `
        <button type="button" class="close" id="dropdownMenuButton" data-toggle="dropdown">
            <svg style="width: 20px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512">
                <path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM0 256c0-17.7 14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32-14.3-32-32zM448 416c0 17.7-14.3 32-32 32H32c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32z"></path>
            </svg>
        </button>
        <div class="dropdown-menu">
            <a class="dropdown-item editCommission"
               data-id="${values.id}"
               data-submagazine="${values.magazineTo}"
               data-receivers="${values.receiversIds.join(',')}"
               data-priority="${values.priority}">
                Edytuj zlecenie
            </a>
        </div>
    ` : '';

    const groupBadge = isGrouped ? `
        <span class="badge badge-warning mt-2">
            <i class="bi bi-layers"></i> Zgrupowane: ${values.groupedCount} zleceń
        </span>
    ` : (values.canBeGrouped ? `
        <span class="badge badge-secondary mt-2">
            <i class="bi bi-stack"></i> Możliwe do zgrupowania: ${values.potentialGroupCount} zleceń
        </span>
    ` : '');

    const productionLink = values.state === 'active' && deviceType !== 'sku' ? `
        <tr>
            <td colspan="2" class="table-light">
                <form class="clickhere"
                      method="POST"
                      action="http://${window.config.baseUrl}/public_html/components/production/production-view.php?redirect=true"
                      target="_blank">
                    <input type="hidden" name="device_type" value="${deviceType}">
                    <input type="hidden" name="device_id" value="${values.deviceBomId}">
                    <a href="#" onclick="$(this).parent().submit()">
                        Kliknij tutaj aby przejść do produkcji.
                    </a>
                </form>
            </td>
        </tr>
    ` : '';

    const returnInput = values.quantityReturned < values.quantityProduced && !isGrouped ? `
        <tr>
            <td class="p-0">
                <input type="number"
                       class="return form-control rounded-0 border-left-0 border-right-0 text-center"
                       placeholder="Wpisz zwracaną ilość" onkeydown="return event.keyCode !== 69">
            </td>
        </tr>
    ` : '';

    const submitButton = values.quantityReturned < values.quantityProduced && !isGrouped ? `
        <button data-id="${values.id}" data-type="${deviceType}"
                data-device_id="${values.deviceId}"
                class="submitToCommission btn btn-primary mx-auto instantPop"
                data-toggle="popover" data-trigger="manual"
                data-content="Zwracana ilość jest większa od produkcji">
            Wyślij
        </button>
    ` : '';

    const laminateInfo = values.deviceLaminate ? `Laminat: <b>${values.deviceLaminate}</b>` : '';
    const versionInfo = values.deviceVersion ? `Wersja: <b>${values.deviceVersion}</b>` : '';

    const warehouseInfo = `<small class="text-muted font-weight-normal ml-1">do <b>${values.magazineFromName}</b></small>`;

    return {
        id: values.id,
        cardClass: values.cardClass,
        color: values.color,
        groupedIdsAttr: isGrouped ? `data-grouped-ids="${values.groupedIds.join(',')}"` : '',
        receiversButton: receiversButton,
        menuButton: menuButton,
        deviceDescription: values.deviceDescription,
        deviceName: values.deviceName,
        laminateInfo: laminateInfo,
        versionInfo: versionInfo,
        warehouseInfo: warehouseInfo,
        groupBadge: groupBadge,
        tableClass: values.tableClass,
        quantity: values.quantity,
        quantityProduced: values.quantityProduced,
        productionLink: productionLink,
        quantityReturned: values.quantityReturned,
        returnInput: returnInput,
        submitButton: submitButton,
        timestampCreated: values.timestampCreated
    };
}

$('#groupCommissions').change(function() {
    loadCommissions();
});

$('body').on('click', '.editCommission', function() {
    let id = $(this).attr("data-id");
    let receivers = $(this).attr("data-receivers");
    let submagazine = $(this).attr("data-submagazine");
    let priority = $(this).attr("data-priority");
    $("#editPriority").val(priority);
    $("#editSubcontractors").empty();
    $("#list__users option").each(function() {
        if ($(this).attr("data-submag") == submagazine) {
            $(this).clone().appendTo('#editSubcontractors');
        }
    });
    $("#editSubcontractors").selectpicker('refresh');
    receivers = receivers.split(',');
    $("#editSubcontractors").selectpicker('val', receivers);
    $("#editCommissionModal").modal("show");
    $(".selectpicker").selectpicker('refresh');
    $("#editCommissionSubmit").attr("data-id", id);
});

$('body').on('click', '.cancelCommission', function() {
    let id = $(this).attr("data-id");
    $("#cancelCommissionSubmit").attr("data-id", id);
    $("#cancelCommissionModal").modal("show");
});

$("#editCommissionSubmit").click(function() {
    let commissionid = $(this).attr("data-id");
    let commissionpriority = $("#editPriority").val();
    let commissionsubcontractors = $("#editSubcontractors").val();
    let $card = $('.card'+commissionid);
    let commissioneditbtn = $card.find(".editCommission");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/index/edit-commission.php",
        data: {
            id: commissionid,
            priority: commissionpriority,
            subcontractors: commissionsubcontractors
        },
        success: function(data) {
            let values = JSON.parse(data);
            $("#editCommissionModal").modal("hide");
            if(values['visibility'] == 'hidethis') {
                $card.remove();
            } else {
                $card.css("box-shadow", "-7px 0px 0px 0px "+values['color']);
                $card.find(".receivers").css("visibility", values['visibility']);
                $card.find(".receivers").attr("data-content", values['receiversPrint']);
                commissioneditbtn.attr("data-priority", values['priority']);
                commissioneditbtn.attr("data-receivers", values['receivers']);
            }
        }
    });
});

$("#cancelCommissionSubmit").click(function() {
    let commissionid = $(this).attr("data-id");
    let $card = $('.card'+commissionid);
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/index/cancel-commission.php",
        data: {
            id: commissionid
        },
        success: function(data) {
            $("#cancelCommissionModal").modal("hide");
            $card.remove();
        }
    });
});

$('body').on('click', '.submitToCommission', function() {
    let $this = $(this);
    $this.prop('disabled', true);
    let commissionid = $(this).attr("data-id");
    let $card = $('.card'+commissionid);
    let quantity = parseInt($card.find(".quantity").html());
    let quantityProduced = parseInt($card.find(".quantityProduced").html());
    let quantityReturned = parseInt($card.find(".quantityReturned").html());
    let quantityReturn = parseInt($card.find(".return").val());
    let type = $(this).attr("data-type");
    let device_id = $(this).attr("data-device_id");
    if ((quantityReturned + quantityReturn) > quantityProduced) {
        $(this).popover('show');
        $(this).prop('disabled', false);
        return;
    }
    if (!quantityReturn) {
        $this.prop('disabled', false);
        return;
    }
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/index/return-production.php",
        data: {
            type: type,
            device_id: device_id,
            commission_id: commissionid,
            quantity: quantityReturn
        },
        success: function(data) {
            let returned = JSON.parse(data);
            $this.prop('disabled', false);
            $card.find('.quantityReturned').text(returned);
            $card.find('.return').val('');
            if(quantity == returned) $card.remove();
        }
    });
});