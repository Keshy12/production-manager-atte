// Flowpin manager functionality
$(document).ready(function() {
    // Progress bar functionality
    const flowpinProgress = $('#flowpinHeaderProgress');
    const flowpinPercent = $('#flowpinHeaderPercent');
    const flowpinIndicator = $('#flowpinIndicator');
    
    // Update progress bar
    function updateFlowpinProgress(percent) {
        flowpinProgress.css('width', percent + '%');
        flowpinPercent.text(percent + '%');
        
        if (percent >= 100) {
            flowpinIndicator.hide();
            setTimeout(() => {
                flowpinProgress.css('width', '0%');
                flowpinPercent.text('0%');
            }, 1000);
        }
    }
    
    // Example progress update (replace with actual data fetching)
    $('#updateDataFromFlowpin').on('click', function() {
        flowpinIndicator.show();
        let progress = 0;
        const interval = setInterval(() => {
            progress += 10;
            updateFlowpinProgress(progress);
            if (progress >= 100) {
                clearInterval(interval);
            }
        }, 500);
    });
    
    // Date updates
    function updateFlowpinDate() {
        // Implementation for updating dates
    }
    
    // Button click handlers
    $('#sendWarehousesToGS, #sendBomFlatToGS, #sendWarehouseComparisonToGS, #sendBomFlatSkuToGS').on('click', function() {
        const button = $(this);
        button.prop('disabled', true);
        // Add actual implementation here
        setTimeout(() => {
            button.prop('disabled', false);
        }, 2000);
    });
});