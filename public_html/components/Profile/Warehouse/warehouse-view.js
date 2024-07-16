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
        url: COMPONENTS_PATH+"/profile/warehouse/warehouse-table.php",
        data: { type: type, components: components, page: page },
        success: function (data) {
            let result = JSON.parse(data);
            let items = result[0];
            let nextPageAvailable = result[1];
            $("#previouspage").prop('disabled', page == 1);
            $("#nextpage").prop('disabled', !nextPageAvailable);

            Object.keys(items).map(function (item) {
                let values = {key: item, 
                    deviceType: items[item].deviceType,
                    componentName: items[item].componentName,
                    componentDescription: items[item].componentDescription,
                    sumQuantity: items[item].sumQuantity};

                let renderedItem = warehouseTableItem.map(render(values)).join('');
                let $renderedItem = $(renderedItem);
                $('#warehouseTable').append($renderedItem);
            });
        }
    });
}

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

$('body').on('click', '.userMagazineCorrection', function() { 
    let device_id = $(this).data("device_id");
    let type = $(this).data("type");
    let quantity = parseInt($(this).parent().find(".quantityInput").val());
    let previous_quantity = parseInt($(this).data("previous_quantity"));
    let difference = quantity - previous_quantity;
    
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/warehouse/correct-warehouse.php",
        data: {
            type: type,
            device_id: device_id,
            difference: difference
        },
        success: function (data) {
            loadMagazine();
        }
    });
})