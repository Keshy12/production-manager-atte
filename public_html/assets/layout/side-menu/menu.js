function getFlowpinDate() {
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/public_html/assets/layout/side-menu/get-flowpin-date.php",
        data: {},
        success: function(data) {
            let result = JSON.parse(data);
            $("#flowpinDate").html(result.flowpin);
            $("#GSWarehouseDate").html(result.gs);
        }
    });
}

function getNotifications() {
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/public_html/assets/layout/side-menu/get-unresolved-notifications.php",
        data: {},
        success: function(data) {
            let result = JSON.parse(data);
            $("#notificationCounter").html(result.count);
            $("#notificationDropdown").html(result.dropdown.join(""));
        }
    });
}

$(document).ready(function(){
    getNotifications();
    getFlowpinDate();
});

$("#toggleFlowpinUpdate").click(function(){
    $(this).html() == "Ukryj" ? $(this).html('Pokaż') : $(this).html('Ukryj');
    $("#flowpinUpdate").toggle();
});

$("#updateDataFromFlowpin").click(function(){
    $("#spinnerflowpin").show();
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/flowpin-sku-update.php",
        data: {},
        success: function(data) {
            if(data.length) alert(data);
            $("#spinnerflowpin").hide();
            getFlowpinDate();
        }
    });
})

$("#sendWarehousesToGS").click(function(){
    $("#spinnerflowpin").show();
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/warehouse-data-gs-upload.php",
        data: {},
        success: function(data) {
            if(data.length) alert(data);
            $("#spinnerflowpin").hide();
            getFlowpinDate();
        }
    });
})