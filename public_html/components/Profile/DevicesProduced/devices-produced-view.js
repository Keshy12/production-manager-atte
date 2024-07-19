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
    let name = $(this).parent().find(".name").text();
    let description = $(this).parent().find(".description").text();
    $("#removeDeviceProduced").attr("data-name", name);
    $("#removeDeviceProduced").attr("data-description", description);
});

$("#removeDeviceProduced").click(function() {
    let type = $(this).attr("data-type");
    let device_id = $(this).attr("data-id");
    let name = $(this).attr("data-name");
    let description = $(this).attr("data-description");
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
                let insert = `<option data-subtext="`+description+`" 
                                data-tokens="`+name+" "+description+`" 
                                value="`+device_id+`">
                                `+name+
                            `</option>`;
                $("#list__"+type).append(insert);
                $("#list__"+type).selectpicker('refresh');
            }
        }
    });
    $("#removeDeviceProducedModal").modal('hide');
})

$(".filter").on('input', function(){
    let type = $(this).attr("data-deviceType");
    let search = this.value.toLowerCase();
    $("#"+type+"Used div").each(function(){
        let $item = $(this);
        let itemNameDescription = $item.find(".name").text()+" "+$item.find(".description").text();
        $item.hide();
        if(~itemNameDescription.toLowerCase().indexOf(search))
        {
            $item.show();
        }
    });
});

$(".delete").click(function(){
    let type = $(this).attr("data-deviceType");
    const devicesToDelete = [];
    $("#"+type+"Used div").each(function(){
        let $item = $(this);
        let itemId = $item.find(".removeDevice").attr("data-id");
        let itemName = $item.find(".name").text();
        let itemDescription = $item.find(".description").text();
        if(!$item.is(":hidden"))
        {
            devicesToDelete.push([itemId, itemName, itemDescription]);
        }
    });
    $("#removeAllDevicesProduced").attr("data-devicesToDelete", JSON.stringify(devicesToDelete));
    $("#removeAllDevicesProduced").attr("data-type", type);
    $("#removeAllDevicesProducedModal").modal('show');
});

$("#removeAllDevicesProduced").click(function(){
    let type = $(this).attr("data-type");
    let devicesToDelete = JSON.parse($(this).attr("data-devicesToDelete"));
    let idsToDelete = devicesToDelete.map( el => el[0] );
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/remove-devices-produced.php",
        data: { type: type, device_id: idsToDelete},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(result[1] === true)
            {
                for (const device of devicesToDelete) {
                    let device_id = device[0];
                    let name = device[1];
                    let description = device[2];
                    $('.'+type+'-'+device_id).remove();
                    let insert = `<option data-subtext="`+description+`" 
                                    data-tokens="`+name+" "+description+`" 
                                    value="`+device_id+`">
                                    `+name+
                                `</option>`;
                    $("#list__"+type).append(insert);
                    $("#list__"+type).selectpicker('refresh');
                }
            }
        }
    });
    $("#removeAllDevicesProducedModal").modal('hide');
});