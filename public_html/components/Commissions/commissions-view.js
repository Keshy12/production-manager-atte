let commissionCard = $('script[data-template="commissionCard"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function renderCards(results) {
    for (let result in results) {
        let items = Array.isArray(results[result]) ? results[result] : [results[result]];
        $("#container")
            .append(items.map(function (item) {
                return commissionCard.map(render(item)).join('');
            }));
    }
    $('[data-toggle="popover"]').popover()
}

function renderCommissions(){
    $("#previouspage").prop('disabled', true);
    $("#nextpage").prop('disabled', true);
    let transferFrom = $("#transferFrom").val();
    let transferTo = $("#transferTo").val();
    let device = [$("#type").val(), $("#list__device").val(), $("#laminate").val(), $("#version").val()];
    let receivers = $("#user").val();
    let state_id = $("#state").val();
    let priority_id = $("#priority").val();
    let showCancelled = $("#showCancelled").prop('checked');
    let page = $("#currentpage").text();
    $.ajax({
        type: "POST",
        url:  COMPONENTS_PATH+"/commissions/get-commissions.php",
        data: {transferFrom: transferFrom, transferTo: transferTo, device: device, receivers: receivers, state_id: state_id, priority_id: priority_id, showCancelled: showCancelled, page: page},
        success: function(data)
        {
            let result = JSON.parse(data);
            let nextPageAvailable = result[1];
            $("#previouspage").prop('disabled', page==1);
            $("#nextpage").prop('disabled', !nextPageAvailable);
            $("#container").empty();
            $("#transferSpinner").hide();
            renderCards(result[0]);
        }
    });
}

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $("#version").append(option);
        $("#version").selectpicker('destroy');
        $("#currentpage").text(1);
        renderCommissions();
    } else {
        for (let version_id in possibleVersions) 
        {
            let version = possibleVersions[version_id][0];
            let option = "<option value='"+version+"'>"+version+"</option>";
            $("#version").append(option);
        }
    }
    $("#version").selectpicker('refresh');
}


function generateLaminateSelect(possibleLaminates){
    if(Object.keys(possibleLaminates).length == 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"' selected>"+laminate_name+"</option>";
        $("#laminate").append(option);
        $("#laminate").selectpicker('destroy');
        $("#version").prop('disabled', false);
        generateVersionSelect(possibleLaminates[laminate_id]['versions']);
    } else {
        for (let laminate_id in possibleLaminates) 
        {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"'>"+laminate_name+"</option>";
            $("#laminate").append(option);
        }
    }
    $("#laminate").selectpicker('refresh');
}

$(document).ready(function(){
    $("[data-toggle=popover]").popover();
    renderCommissions();
})

$("#type").change(function(){
    $("#list__device").empty();
    $('#list__'+this.value+' option').clone().appendTo('#list__device');
    $('#list__device').prop("disabled", false);
    $('.selectpicker').selectpicker('refresh');
    $("#version").empty();
    $("#version, #laminate").prop('disabled', true);
    $("#list__laminate").hide();
    if(this.value == 'smd') $("#list__laminate").show();
    $("#version, #laminate").selectpicker('refresh');
});

$("#list__device").change(function(){
    $("#version").empty();
    $("#laminate").empty();
    if($("#type").val() == 'smd') {
        $("#laminate").prop('disabled', false);
        $("#version").prop('disabled', true);
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        generateLaminateSelect(possibleLaminates);
    } else if($("#type").val() == 'tht') {
        $("#version").prop('disabled', false);
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    } else {
        $("#version").selectpicker('destroy');
        $("#version").html("<option value=\"n/d\" selected>n/d</option>");
        $("#version").prop('disabled', false);
        renderCommissions();
    }
    $("#version, #laminate").selectpicker('refresh');
});
$("#list__laminate").change(function(){
    let possibleVersions = $("#laminate option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#version").prop('disabled', false);
    $("#version").selectpicker('refresh');
})

$("#clearDevice").click(function(){
    $("#currentpage").text(1);
    $("#list__laminate").hide();
    $('#type').val(0) 
    $("#list__device, #version, #laminate").empty();
    $("#type, #list__device, #version, #laminate").selectpicker('refresh');
    renderCommissions();
});

$("#clearMagazine").click(function(){
    $("#currentpage").text(1);
    $('#transferFrom, #transferTo').val('') 
    $('#transferFrom, #transferTo').selectpicker('refresh');
    renderCommissions();
});

$("#transferFrom, #transferTo, #version, #showCancelled").change(function(){
    $("#currentpage").text(1);
    renderCommissions(); 
});
$("#user, #state, #priority").on('hide.bs.select', function(){
    $("#currentpage").text(1);
    renderCommissions(); 
});

$("#previouspage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    if (page != 1) {
        page--;
        $("#currentpage").text(page);
        renderCommissions();
    }
});

$("#nextpage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    page++;
    $("#currentpage").text(page);
    renderCommissions();
});