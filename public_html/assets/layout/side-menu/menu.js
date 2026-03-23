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
        url: COMPONENTS_PATH + "/Admin/Synchronization/flowpin/update/get-progress.php",
        data: {},
        success: function(data) {
            isFlowpinRunning = data.success && data.status === 'running';
            if (isFlowpinRunning) {
                $("#flowpinHeaderProgress").show();
                $("#flowpinHeaderProgressBar").css("width", data.percentage + "%");
                $("#flowpinHeaderPercent").text(data.percentage.toFixed(0) + "%");
                $("#flowpinSyncLink").text("Flowpin [" + data.percentage.toFixed(0) + "%]");
            } else {
                $("#flowpinHeaderProgress").hide();
                $("#flowpinSyncLink").text("Flowpin");
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


// Note: Google Sheets button handlers moved to sheets-view.js component
