
function generateLastProduction(deviceId){
    let lastId = $("#lastProduction").attr("data-last-id");
    $("#lastProduction").load('../public_html/components/production/last-production-table.php', 
    {deviceType: 'tht', deviceId: deviceId, lastId: lastId},
    function(){
        let lastIdNew = $("#lastProductionTable").attr("data-last-id");
        $("#lastProduction").attr("data-last-id", lastIdNew);
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

$("#list__device").change(function(){
    $("#laminate, #version").empty();
    $("#laminate, #version").selectpicker('refresh');
    let possibleVersions = $("#list__device option:selected").data("jsonversions");
    let deviceDescription = $("#list__device option:selected").data("subtext");
    $("#device_description").val(deviceDescription);
    generateVersionSelect(possibleVersions);
    generateLastProduction(this.value);
});

$("#form").submit(function(e) {
    //Avoid to execute the actual submit of the form.
    e.preventDefault();
    //When doing correction (negative quantity), commment is required.
    if($("#quantity").val() < 0 && !$.trim($("#comment").val()))
    {
        $('#correctionModal').modal('show');
        return;
    }
    $("#send").html("Wysyłanie");
    $("#send").prop("disabled", true);
    var $form = $(this);
    var actionUrl = $form.attr('action');
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: $form.serialize(), // serializes the form's elements.
        success: function(data)
        {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            generateLastProduction($("#list__device option:selected").val());
        }
    });

});


$(document).ready(function(){
    let autoSelectValues = JSON.parse($("#list__device").attr("data-auto-select"));
    if(autoSelectValues.length) {
        $("#list__device").selectpicker('val', autoSelectValues[0]).change();
        $("#version").selectpicker('val', autoSelectValues[1]).change();
    }
});