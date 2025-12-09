let fromOrdersRenderer;

$(document).ready(function() {
    // Initialize renderer
    fromOrdersRenderer = new FromOrdersRenderer();

    // Initialize Bootstrap components
    $('.selectpicker').selectpicker();

    // Attach event handlers
    attachEventHandlers();
});

function attachEventHandlers() {
    // Download orders button
    $("#downloadOrders").click(async function() {
        await fromOrdersRenderer.fetchInitialData();
    });

    // Import orders button
    $("#importOrders").click(function() {
        fromOrdersRenderer.importOrders();
    });

    // Filter change handlers
    $("#filterGrnId, #filterPoId, #filterPartName").change(function() {
        fromOrdersRenderer.resetToFirstPage();
        fromOrdersRenderer.render();
    });

    $("#filterDateFrom, #filterDateTo").change(function() {
        fromOrdersRenderer.resetToFirstPage();
        fromOrdersRenderer.render();
    });

    // Clear filters button
    $("#clearFilters").click(function() {
        $("#filterGrnId, #filterPoId").val('').selectpicker('refresh');
        $("#filterPartName").val([]).selectpicker('refresh');
        $("#filterDateFrom, #filterDateTo").val('');

        fromOrdersRenderer.resetToFirstPage();
        fromOrdersRenderer.render();
    });
}
