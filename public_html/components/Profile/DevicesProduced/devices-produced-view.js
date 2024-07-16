$(document).ready(function() {

});

function addDevices(devices, type)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/add-devices-produced.php",
        data: { type: type, device_id: devices},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];
            let addedDevices = (1 in result) ? result[1] : false;

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(addedDevices !== false)
            {
                Object.keys(addedDevices).map(function (device) {
                    $("#"+type+"Used").append(addedDevices[device]);
                });
            }

        }
    });
}

$("#addTHT").click(function(){
    let devices = $("#list__tht").selectpicker('val');
    addDevices(devices, "tht");
    $('#list__tht').find(":selected").remove();
    $("#list__tht").selectpicker('refresh');
});

$("#addSMD").click(function(){
    let devices = $("#list__smd").selectpicker('val');
    addDevices(devices, "smd");
    $('#list__smd').find(":selected").remove();
    $("#list__smd").selectpicker('refresh');
});

$('body').on('click', '.removeDevice', function() {
    $("#removeDeviceProducedModal").modal('show');
    $("#removeDeviceProduced").attr("data-type", $(this).attr("data-type"));
    $("#removeDeviceProduced").attr("data-id", $(this).attr("data-id"));
});

$("#removeDeviceProduced").click(function() {
    let type = $(this).attr("data-type");
    let device_id = $(this).attr("data-id");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/remove-devices-produced.php",
        data: { type: type, device_id: device_id},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(result[1] === true)
            {
                $('.'+type+'-'+device_id).remove();
            }
        }
    });
    $("#removeDeviceProducedModal").modal('hide');
})