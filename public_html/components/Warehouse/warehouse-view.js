let warehouseTableItem = $('script[data-template="warehouseTableItem"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function loadMagazine() {
    $("#warehouseTable").empty();
    let components = $("#list__components").val();
    let page = parseInt($("#currentpage").text());
    let type = $("#magazinecomponent").val();
    let items = components.slice((page - 1) * 10, ((page - 1) * 10) + 11);
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/warehouse/warehouse-table.php",
        data: { type: type, components: items, page: page },
        success: function (data) {
            let result = JSON.parse(data);
            let items = result[0];
            let nextPageAvailable = result[1];
            $("#previouspage").prop('disabled', page == 1);
            $("#nextpage").prop('disabled', !nextPageAvailable);
            Object.keys(items).map(function (item) {
                let values = {key: item,
                    componentName: items[item].componentName,
                    componentDescription: items[item].componentDescription,
                    sumType1: items[item][1].typeQuantitySum,
                    sumType2: items[item][2].typeQuantitySum,
                    sumAll: items[item][1].typeQuantitySum + items[item][2].typeQuantitySum}
                delete items[item][1].typeQuantitySum;
                delete items[item][2].typeQuantitySum;

                let renderedItem = warehouseTableItem.map(render(values)).join('');
                let $renderedItem = $(renderedItem);

                // Handle main warehouses
                let mainWarehouses = items[item][1];
                let $mainWarehouses = $renderedItem.find(".mainWarehouses");

                for(const key in mainWarehouses)
                {
                    let isActive = mainWarehouses[key].isActive;
                    let warehouseHtml = mainWarehouses[key].name+": ";
                    warehouseHtml += "<b>"+mainWarehouses[key].quantity+"</b><br>";

                    if (!isActive) {
                        warehouseHtml = '<span class="text-danger">' + warehouseHtml + '</span>';
                    }

                    $mainWarehouses.append(warehouseHtml);
                }

                let mainWarehousesJSON = JSON.stringify(mainWarehouses);
                $mainWarehouses.append(`<button data-device_id='`+item+`' 
                data-values='`+mainWarehousesJSON+`' 
                class="btn btn-primary my-2 magazineCorrection">Korekta</button>`)

                // Handle other warehouses
                let otherWarehouses = items[item][2];
                let $otherWarehouses = $renderedItem.find(".otherWarehouses");

                for(const key in otherWarehouses)
                {
                    if(otherWarehouses[key].quantity == 0)
                    {
                        delete otherWarehouses[key];
                        continue;
                    }

                    let isActive = otherWarehouses[key].isActive;
                    let warehouseHtml = otherWarehouses[key].name+": ";
                    warehouseHtml += "<b>"+otherWarehouses[key].quantity+"</b><br>";

                    if (!isActive) {
                        warehouseHtml = '<span class="text-danger">' + warehouseHtml + '</span>';
                    }

                    $otherWarehouses.append(warehouseHtml);
                }

                let otherWarehousesJSON = JSON.stringify(otherWarehouses);
                $otherWarehouses.append(`<button data-device_id='`+item+`' 
                data-values='`+otherWarehousesJSON+`' 
                class="btn btn-primary my-2 magazineCorrection">Korekta</button>`)

                $('#warehouseTable').append($renderedItem);
            });
        }
    });
}

$("#nextpage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    page++;
    $("#currentpage").text(page);
    loadMagazine();
});

$("#previouspage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    if (page != 1) {
        page--;
        $("#currentpage").text(page);
        loadMagazine();
    }
});

$("#magazinecomponent").change(function () {
    let option = $(this).val();
    $("#currentpage").text("1");
    $(".magazineoption").removeClass("btn-secondary");
    $(".magazineoption").addClass("btn-outline-secondary");
    $(".magazineoption[value="+option+"]").removeClass("btn-outline-secondary");
    $(".magazineoption[value="+option+"]").addClass("btn-secondary");
    $("#magazinetbody").empty();
    $("#list__components").empty();
    $('#list__' + option + ' option').clone().appendTo('#list__components');
    $("#list__components").prop("disabled", false);
    $("#list__components").selectpicker('refresh');
    loadMagazine();
});

$(".magazineoption").click(function(){
    $(".magazineoption").removeClass("btn-secondary");
    $(".magazineoption").addClass("btn-outline-secondary");
    $(this).removeClass("btn-outline-secondary");
    $(this).addClass("btn-secondary");
    let option = $(this).val();
    $("#magazinecomponent").val(option).change();
});

$('#list__components').on('hide.bs.select', function () {
    $("#nextpage, #previouspage").prop('disabled', false);
    $("#currentpage").text("1");
    loadMagazine();
});

$(document).ready(function() {
    loadMagazine();
});

$('body').on('click', '.magazineCorrection', function () {
    let values = JSON.parse($(this).attr("data-values"));
    let device_id = $(this).attr("data-device_id");
    $("#correctMagazineSubmit").attr("data-device_id", device_id);
    $("#correctMagazineModal").modal('show');
    $("#correctMagazineInputGroup").empty();
    for (const item in values) {
        let input_group = `
        <div class="input-group">
            <div class="input-group-prepend">
                <span class="input-group-text"">
                `+ values[item]['name'] + `: </span>
            </div>
            <input type="number" data-magazine_id="`+ item + `" 
            data-previous_value="` + values[item]['quantity'] + `" 
            class="form-control correctionInput" 
            value="` + values[item]['quantity'] + `" />
        </div>
        `
        $("#correctMagazineInputGroup").append(input_group);
    }
});

$("#correctMagazineSubmit").click(function () {
    let result = [];
    let device_id = $(this).attr("data-device_id");
    let type = $("#magazinecomponent").val();
    $("#correctMagazineInputGroup .input-group .correctionInput").each(function () {
        let $correctionInput = $(this);
        let magazine_id = $correctionInput.attr("data-magazine_id");
        let quantity = parseFloat($correctionInput.val());
        let previousQuantity = parseFloat($correctionInput.attr("data-previous_value"));
        let difference = quantity - previousQuantity;
        if (difference != 0) result.push([magazine_id, difference]);
    });
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/warehouse/correct-warehouse.php",
        data: {
            result: result,
            type: type,
            device_id: device_id
        },
        success: function (data) {
            loadMagazine();
        }
    });
});