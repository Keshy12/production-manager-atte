
function generateLastProduction(deviceId){
    $("#lastProduction").load('../public_html/components/production/last-production-table.php', {deviceType: 'smd', deviceId: deviceId});
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


function generateLaminateSelect(possibleLaminates){
    if(Object.keys(possibleLaminates).length == 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"' selected>"+laminate_name+"</option>";
        $("#laminate").append(option);
        $("#laminate").selectpicker('destroy');
        $("#laminate").selectpicker('');
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

$("#list__device").change(function(){
    $("#laminate, #version").empty();
    $("#laminate, #version").selectpicker('refresh');
    let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
    let deviceDescription = $("#list__device option:selected").data("subtext");
    $("#device_description").val(deviceDescription);
    generateLaminateSelect(possibleLaminates);
    generateLastProduction(this.value);
});

$("#laminate").change(function(){
    let possibleVersions = $("#laminate option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
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