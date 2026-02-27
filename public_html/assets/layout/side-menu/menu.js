let isFlowpinRunning = false;
let isGsUploadRunning = false;
let gsUploadInterval = null;

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

function updateIndicatorVisibility() {
    if (isFlowpinRunning || isGsUploadRunning) {
        $("#flowpinIndicator").show();
    } else {
        $("#flowpinIndicator").hide();
    }
}

function updateFlowpinHeaderProgress() {
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/public_html/components/FlowpinUpdate/get-progress.php",
        data: {},
        success: function(data) {
            isFlowpinRunning = data.success && data.status === 'running';
            if (isFlowpinRunning) {
                $("#flowpinHeaderProgress").show();
                $("#flowpinHeaderProgressBar").css("width", data.percentage + "%");
                $("#flowpinHeaderPercent").text(data.percentage.toFixed(0) + "%");
            } else {
                $("#flowpinHeaderProgress").hide();
            }
            updateIndicatorVisibility();
        }
    });
}

function updateGsUploadStatus() {
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/public_html/components/GoogleSheets/get-upload-status.php",
        success: function(data) {
            isGsUploadRunning = data.success && data.status === 'running';
            $("#sendWarehousesToGS").prop("disabled", isGsUploadRunning);
            
            if (!isGsUploadRunning && gsUploadInterval) {
                clearInterval(gsUploadInterval);
                gsUploadInterval = null;
                getFlowpinDate(); // Refresh the date since the process is finished
            }
            updateIndicatorVisibility();
        }
    });
}

$(document).ready(function(){
    getNotifications();
    getFlowpinDate();
    updateFlowpinHeaderProgress();
    updateGsUploadStatus(); // Check on page load
    setInterval(updateFlowpinHeaderProgress, 60000); // Polling every minute
});

$("#toggleFlowpinUpdate").click(function(){
    const textSpan = $(this).find(".toggle-text");
    textSpan.text(textSpan.text() === "Ukryj" ? "Pokaż" : "Ukryj");
    $("#flowpinUpdate").toggle();
});


$("#sendWarehousesToGS").click(function(){
    $(this).prop("disabled", true);
    isGsUploadRunning = true;
    updateIndicatorVisibility();

    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/warehouse-data-gs-upload.php",
        data: {},
        success: function(data) {
            if(data.length && !data.includes("Process is already running")) alert(data);
            // Polling will handle UI updates
        },
        error: function() {
            alert("An error occurred while trying to send data to Google Sheets.");
        }
    });

    if (!gsUploadInterval) {
        gsUploadInterval = setInterval(updateGsUploadStatus, 3000); // Poll every 3s
    }
});

$("#sendBomFlatToGS").click(function(){
    $(this).prop("disabled", true);
    
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/bom-flat-tht-gs-upload.php",
        data: {},
        success: function(data) {
            alert("BOM_FLAT: " + data);
            $("#sendBomFlatToGS").prop("disabled", false);
        },
        error: function() {
            alert("An error occurred while trying to send BOM_FLAT to Google Sheets.");
            $("#sendBomFlatToGS").prop("disabled", false);
        }
    });
});

$("#sendWarehouseComparisonToGS").click(function(){
    $(this).prop("disabled", true);
    
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/warehouse-comparison-gs-upload.php",
        data: {},
        success: function(data) {
            alert("Porównanie Stanów: " + data);
            $("#sendWarehouseComparisonToGS").prop("disabled", false);
        },
        error: function() {
            alert("An error occurred while trying to send warehouse comparison to Google Sheets.");
            $("#sendWarehouseComparisonToGS").prop("disabled", false);
        }
    });
});

$("#sendBomFlatSkuToGS").click(function(){
    $(this).prop("disabled", true);
    
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/src/cron/bom-flat-sku-gs-upload.php",
        data: {},
        success: function(data) {
            alert("BOM_FLAT_SKU: " + data);
            $("#sendBomFlatSkuToGS").prop("disabled", false);
        },
        error: function() {
            alert("An error occurred while trying to send BOM_FLAT_SKU to Google Sheets.");
            $("#sendBomFlatSkuToGS").prop("disabled", false);
        }
    });
});
