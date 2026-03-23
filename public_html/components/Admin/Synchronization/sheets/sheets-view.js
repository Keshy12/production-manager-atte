// Handle tab switching based on URL hash fragments
$(document).ready(function() {
    // Activate tab based on URL hash on page load
    const hash = window.location.hash;

    // Check if hash matches a valid tab ID
    if (hash === '#update-prices' || hash === '#import-orders' || hash === '#integration') {
        // Find the tab link by href and show it
        $(`a[href="${hash}"]`).tab('show');
    }

    // Listen for hash changes (e.g., when user clicks back/forward buttons)
    $(window).on('hashchange', function() {
        const newHash = window.location.hash;

        // Only switch tabs for valid tab hashes
        if (newHash === '#update-prices' || newHash === '#import-orders' || newHash === '#integration') {
            $(`a[href="${newHash}"]`).tab('show');
        }
    });

    // Initialize Google Sheets functionality
    getFlowpinDate();
    updateGsUploadStatus();

    // Bind click handlers for Google Sheets buttons
    bindGoogleSheetsHandlers();
});

// Note: isGsUploadRunning and gsUploadInterval are declared in menu.js

function getFlowpinDate() {
    $.ajax({
        type: "POST",
        url: "/atte_ms_new/public_html/assets/layout/side-menu/get-flowpin-date.php",
        data: {},
        success: function(data) {
            let result = JSON.parse(data);
            $("#GSWarehouseDate").html(result.gs);
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
        }
    });
}

function bindGoogleSheetsHandlers() {
    $("#sendWarehousesToGS").click(function(){
        $(this).prop("disabled", true);
        isGsUploadRunning = true;

        $.ajax({
            type: "POST",
            url: "/atte_ms_new/src/cron/warehouse-data-gs-upload.php",
            data: {},
            success: function(data) {
                if(data.length && !data.includes("Process is already running")) alert(data);
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
}
