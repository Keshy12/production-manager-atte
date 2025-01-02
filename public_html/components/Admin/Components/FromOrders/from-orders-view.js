$(document).ready(function() {
   const orders = getOrders();
   if(orders !== false) {
        generateOrdersTable(orders);
   };
});

function generateMissingPartsError(missingParts) {
    $("#errorAlert").show();
    $("#missingParts").html(missingParts.join(", "));
}

function getOrders() {
    let result = null;
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/components/fromorders/get-orders.php",
        data: {},
        async: false,
        success: function(data) {
            const missingParts = JSON.parse(data)[1]; 
            if(missingParts.length !== 0) {
                generateMissingPartsError(missingParts);
                result = false;
                return;
            }
            result = JSON.parse(data)[0];
        }
    });
    return result;
}