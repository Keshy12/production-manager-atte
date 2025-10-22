/**
 * commissions-view-main.js
 * Main entry point for commissions view
 * Handles filter changes and delegates rendering to CommissionsRenderer
 */

// Global renderer instance
let commissionsRenderer;

$(document).ready(function() {
    // Initialize renderer
    commissionsRenderer = new CommissionsRenderer();

    // Initialize Bootstrap components
    initializeBootstrapComponents();

    // Attach filter event handlers
    attachFilterHandlers();

    // Initial render
    commissionsRenderer.render();
});

/**
 * Initialize Bootstrap components (selectpicker, popovers, etc.)
 */
function initializeBootstrapComponents() {
    // Initialize all selectpickers
    $('.selectpicker').selectpicker();

    // Initialize popovers
    $('[data-toggle="popover"]').popover();
}

/**
 * Attach event handlers for all filters
 */
function attachFilterHandlers() {
    // Device type change
    $("#type").change(function() {
        handleDeviceTypeChange(this.value);
    });

    // Device selection change
    $("#list__device").change(function() {
        handleDeviceSelectionChange();
    });

    // Laminate change (for SMD)
    $("#list__laminate").change(function() {
        handleLaminateChange();
    });

    // Clear device filter
    $("#clearDevice").click(function() {
        clearDeviceFilter();
    });

    // Clear magazine filter
    $("#clearMagazine").click(function() {
        clearMagazineFilter();
    });

    // Transfer warehouses change
    $("#transferFrom, #transferTo").change(function() {
        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    });

    // Version change
    $("#version").change(function() {
        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    });

    // Show cancelled checkbox
    $("#showCancelled").change(function() {
        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    });

    // Group together checkbox
    $("#groupTogether").change(function() {
        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    });

    // User, State, Priority multi-selects
    $("#user, #state, #priority").on('hide.bs.select', function() {
        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    });

    // Transfer To change (filter users by warehouse)
    $("#transferTo").change(function() {
        const transferTo = this.value;
        filterUsersByWarehouse(transferTo);
    });
}

/**
 * Handle device type change (SKU, THT, SMD)
 */
function handleDeviceTypeChange(deviceType) {
    // Clear device selection
    $("#list__device").empty();

    // Clone options from hidden select
    $('#list__' + deviceType + ' option').clone().appendTo('#list__device');

    // Enable device select
    $('#list__device').prop("disabled", false);

    // Clear and disable version/laminate
    $("#version, #laminate").empty();
    $("#version, #laminate").prop('disabled', true);

    // Show/hide laminate for SMD
    if (deviceType === 'smd') {
        $("#list__laminate").show();
    } else {
        $("#list__laminate").hide();
    }

    // Refresh selectpickers
    $('.selectpicker').selectpicker('refresh');
}

/**
 * Handle device selection change
 */
function handleDeviceSelectionChange() {
    const deviceType = $("#type").val();

    // Clear version and laminate
    $("#version").empty();
    $("#laminate").empty();

    if (deviceType === 'smd') {
        // SMD: Enable laminate, disable version
        $("#laminate").prop('disabled', false);
        $("#version").prop('disabled', true);

        const possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        generateLaminateSelect(possibleLaminates);

    } else if (deviceType === 'tht') {
        // THT: Enable version
        $("#version").prop('disabled', false);

        const possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);

    } else {
        // SKU: Set version to n/d and render
        $("#version").selectpicker('destroy');
        $("#version").html("<option value=\"n/d\" selected>n/d</option>");
        $("#version").prop('disabled', false);

        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();
    }

    $("#version, #laminate").selectpicker('refresh');
}

/**
 * Handle laminate change (for SMD)
 */
function handleLaminateChange() {
    const possibleVersions = $("#laminate option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#version").prop('disabled', false);
    $("#version").selectpicker('refresh');
}

/**
 * Generate version select options
 */
function generateVersionSelect(possibleVersions) {
    $("#version").empty();

    if (!possibleVersions || Object.keys(possibleVersions).length === 0) {
        return;
    }

    // Single version - auto-select and render
    if (Object.keys(possibleVersions).length === 1) {
        if (possibleVersions[0] == null) {
            $("#version").selectpicker('destroy');
            $("#version").html("<option value=\"n/d\" selected>n/d</option>");
            $("#version").prop('disabled', false);
            $("#version").selectpicker('refresh');

            commissionsRenderer.resetToFirstPage();
            commissionsRenderer.render();
            return;
        }

        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='" + version + "' selected>" + version + "</option>";
        $("#version").append(option);
        $("#version").selectpicker('destroy');

        commissionsRenderer.resetToFirstPage();
        commissionsRenderer.render();

    } else {
        // Multiple versions - let user select
        for (let version_id in possibleVersions) {
            let version = possibleVersions[version_id][0];
            let option = "<option value='" + version + "'>" + version + "</option>";
            $("#version").append(option);
        }
    }

    $("#version").selectpicker('refresh');
}

/**
 * Generate laminate select options (for SMD)
 */
function generateLaminateSelect(possibleLaminates) {
    if (!possibleLaminates || Object.keys(possibleLaminates).length === 0) {
        return;
    }

    // Single laminate - auto-select
    if (Object.keys(possibleLaminates).length === 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='" + laminate_id + "' data-jsonversions='" + versions + "' selected>" + laminate_name + "</option>";
        $("#laminate").append(option);
        $("#laminate").selectpicker('destroy');
        $("#version").prop('disabled', false);

        generateVersionSelect(possibleLaminates[laminate_id]['versions']);

    } else {
        // Multiple laminates - let user select
        for (let laminate_id in possibleLaminates) {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='" + laminate_id + "' data-jsonversions='" + versions + "'>" + laminate_name + "</option>";
            $("#laminate").append(option);
        }
    }

    $("#laminate").selectpicker('refresh');
}

/**
 * Clear device filter
 */
function clearDeviceFilter() {
    commissionsRenderer.resetToFirstPage();

    $("#list__laminate").hide();
    $('#type').val('');
    $("#list__device, #version, #laminate").empty();
    $("#list__device, #version, #laminate").prop('disabled', true);
    $("#type, #list__device, #version, #laminate").selectpicker('refresh');

    commissionsRenderer.render();
}

/**
 * Clear magazine filter
 */
function clearMagazineFilter() {
    commissionsRenderer.resetToFirstPage();

    $('#transferFrom, #transferTo').val('');
    $('#transferFrom, #transferTo').selectpicker('refresh');

    filterUsersByWarehouse('');

    commissionsRenderer.render();
}

/**
 * Filter users by warehouse
 */
function filterUsersByWarehouse(warehouseId) {
    $("#user option").each(function() {
        const userWarehouseId = $(this).attr("data-submag-id");
        const shouldDisable = warehouseId !== '' && userWarehouseId !== warehouseId;

        $(this).prop("disabled", shouldDisable);

        // Deselect disabled options
        if (shouldDisable) {
            $(this).prop("selected", false);
        }
    });

    $("#user").selectpicker('refresh');
}

/**
 * Show success message
 */
function showSuccessMessage(message) {
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;

    $("#ajaxResult").append(alertHtml);

    setTimeout(function() {
        $(".alert-success").alert('close');
    }, 3000);
}

/**
 * Show error message
 */
function showErrorMessage(message) {
    const alertHtml = `
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;

    $("#ajaxResult").append(alertHtml);

    setTimeout(function() {
        $(".alert-danger").alert('close');
    }, 5000);
}

/**
 * Refresh commissions view
 * Can be called from external scripts
 */
function refreshCommissions() {
    if (commissionsRenderer) {
        commissionsRenderer.render();
    }
}

// Export functions for use in other scripts
window.refreshCommissions = refreshCommissions;
window.showSuccessMessage = showSuccessMessage;
window.showErrorMessage = showErrorMessage;