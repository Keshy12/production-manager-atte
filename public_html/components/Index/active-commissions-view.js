$(document).ready(function(){
    $("[data-toggle=popover]").popover();
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
$('#user').selectpicker({
    countSelectedText: "Wybrano {0}",
    selectAllText: "Zaznacz wszystkie",
    deselectAllText: "Odznacz wszystkie"
});
$("#editCommissionSubmit").click(function() {
    let commissionid = $(this).attr("data-id");
    let commissionpriority = $("#editPriority").val();
    let commissionsubcontractors = $("#editSubcontractors").val();
    let $card = $('.card'+commissionid);
    let commissioneditbtn = card.find(".editCommission");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/index/edit-commission.php",
        data: {
            id: commissionid,
            priority: commissionpriority,
            subcontractors: commissionsubcontractors
        },
        success: function(data) {
            let values = JSON.parse(data);
            $("#editCommissionModal").modal("hide");
            if(values['visibility'] == 'hidethis') $card.remove();
            $card.css("box-shadow", "-7px 0px 0px 0px "+values['color']);
            $card.find(".receivers").css("visibility", values['visibility'])
            $card.find(".receivers").attr("data-content", values['receiversPrint'])
            commissioneditbtn.attr("data-priority", values['priority']);
            commissioneditbtn.attr("data-receivers", values['receivers']);
            
        }
    });
});
$("#cancelCommissionSubmit").click(function() {
    let commissionid = $(this).attr("data-id");
    let $card = $('.card'+commissionid);
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/index/cancel-commission.php",
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
        url: COMPONENTS_PATH+"/index/return-production.php",
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
})