let warehouseTableItem = $('script[data-template="warehouseTableItem"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function loadMagazine() {
    $("#warehouseTable").empty();
    let components = $("#list__components").val();
    let page = parseInt($("#currentpage").text());
    let type = $("#magazinecomponent").val();
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/warehouse/warehouse-table.php",
        data: { type: type, components: components, page: page },
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

                let mainWarehouses = items[item][1];
                let $mainWarehouses = $renderedItem.find(".mainWarehouses");
                for(const key in mainWarehouses)
                {
                    $mainWarehouses.append(mainWarehouses[key].name+": ");
                    $mainWarehouses.append("<b>"+mainWarehouses[key].quantity+"</b><br>");
                }
                let mainWarehousesJSON = JSON.stringify(mainWarehouses);
                $mainWarehouses.append(`<button data-device_id='`+item+`' 
                data-values='`+mainWarehousesJSON+`' 
                class="btn btn-primary my-2 magazineCorrection">Korekta</button>`)

                let otherWarehouses = items[item][2];
                let $otherWarehouses = $renderedItem.find(".otherWarehouses");
                for(const key in otherWarehouses)
                {
                    if(otherWarehouses[key].quantity == 0)
                    {
                        delete otherWarehouses[key];
                        continue;
                    }
                    $otherWarehouses.append(otherWarehouses[key].name+": ");
                    $otherWarehouses.append("<b>"+otherWarehouses[key].quantity+"</b><br>");
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