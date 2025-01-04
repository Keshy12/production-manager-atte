const fromOrders_template = $('script[data-template="fromOrders_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$(document).ready(function() {
    const orders = [];
    let newLastFoundCell = null;
    $("#downloadOrders").click(async function() {
        $("#missingPartsAlert, #errorAlert, #successAlert").hide();
        $(".spinnerFromOrders").show();
        try {
            const [orderResults, missingParts, lastFoundCell] = await getOrders();
            newLastFoundCell = lastFoundCell;
            orders.push(...orderResults);
            if(orders.length === 0) {
                generateError("Nie znaleziono żadnych nowych zamówień.");
                return;
            }
            renderOrdersTable(orders, 1);
            if (missingParts.length !== 0) {
                generateMissingPartsError(missingParts);
            } else {
                $("#importOrders").prop('disabled', false);
            }
        } catch (error) {
            console.error("Error fetching orders:", error);
        } finally {
            $(".spinnerFromOrders").hide(); // Hide spinner after completion
        }
    });

    $("#previousPage").click(function() {
        $("#nextPage, #previousPage").prop('disabled', true);
        let page = parseInt($("#currentPage").text());
        page--;
        $("#currentPage").text(page);
        renderOrdersTable(orders, page);
    });
    
    $("#nextPage").click(function() {
        $("#nextPage, #previousPage").prop('disabled', true);
        let page = parseInt($("#currentPage").text());
        page++;
        $("#currentPage").text(page);
        renderOrdersTable(orders, page);
    });

    $("#importOrders").click(function() {
        const oldLastFoundCell = parseInt($("#lastFoundCell").text());
        importOrders(orders, oldLastFoundCell, newLastFoundCell);
    });
});

function importOrders(orders, oldLastCellFound, newLastCellFound) {
    data = {orders: JSON.stringify(orders), oldLastCellFound: oldLastCellFound, newLastCellFound: newLastCellFound};
    $.ajax({
        url: COMPONENTS_PATH + "/admin/components/fromorders/import-orders.php",
        type: 'POST',
        data: data,
        success: function(response) {
            if(response.length !== 0) {
                generateError(response);
                return;
            }
            $("#successAlert").show();
            $("#successAlert").html("Zamówienia zostały zaimportowane pomyślnie.");
            $("#importOrders").prop('disabled', true);
        }
    });
}

function renderOrdersTable(orders, page) {
    const $TBody = $("#fromOrdersTBody");
    $TBody.empty(); // Clear existing rows

    const itemsPerPage = 10;
    const start = (page - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const paginatedOrders = orders.slice(start, end);

    paginatedOrders.forEach(item => {
        let renderedItem = fromOrders_template.map(render(item)).join('');
        let $renderedItem = $(renderedItem);
        $TBody.append($renderedItem);
    });

    // Check if next page exists
    const totalPages = Math.ceil(orders.length / itemsPerPage);
    if (page >= totalPages) {
        $("#nextPage").prop('disabled', true);
    } else {
        $("#nextPage").prop('disabled', false);
    }

    $("#previousPage").prop('disabled', page === 1);
}

function generateMissingPartsError(missingParts) {
    $("#missingPartsAlert").show();
    $("#missingParts").html(missingParts.join(", "));
}

function generateError(message) {
    $("#errorAlert").html(message);
    $("#errorAlert").show();
}

async function getOrders() {
    try {
        const response = await $.ajax({
            type: "POST",
            url: COMPONENTS_PATH + "/admin/components/fromorders/get-orders.php",
            data: {}
        });
        const ajaxResult = JSON.parse(response);
        return [ajaxResult[0], ajaxResult[1], ajaxResult[2]]; // Return orders and missing parts
    } catch (error) {
        console.error("AJAX Error:", error);
        throw error; // Propagate error for handling in the calling function
    }
}