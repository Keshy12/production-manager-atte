
function generateLastProduction(deviceId, lastId){
    $("#lastProduction").load('../public_html/components/production/last-production-table.php', 
    {deviceType: 'tht', deviceId: deviceId, lastId: lastId});
}

function generateMarking(marking){
    $("#marking").empty();
    for (let mark in marking)
    {
        mark = parseInt(mark);
        let bMarking = marking[mark];
        let fileName = (mark+1)+"off.png";
        if(bMarking) fileName = (mark+1)+"on.png";
        $("#marking").append(`<img style='width:33%;' 
                                   class='img-fluid mt-4' 
                                   src='/atte_ms_new/public_html/assets/img/production/tht/marking/`+fileName+`' 
                                   alt='oznaczenie'>`);
    } 

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
    $("#laminate, #version, #alerts").empty();
    $("#laminate, #version").selectpicker('refresh');
    let possibleVersions = $("#list__device option:selected").data("jsonversions");
    let marking = $("#list__device option:selected").data("jsonmarking");
    let deviceDescription = $("#list__device option:selected").data("subtext");
    $("#device_description").val(deviceDescription);
    generateVersionSelect(possibleVersions);
    generateMarking(marking);
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
            const result = JSON.parse(data);
            let lastId = result[0];
            let alerts = result[1];
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            generateLastProduction($("#list__device option:selected").val(), lastId);

            $("#alerts").empty();

            alerts.forEach(function(alert) {
                $("#alerts").append(alert);
            });
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