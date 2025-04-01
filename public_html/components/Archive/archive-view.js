
function loadarchive()
{   
    $("#archiveTable").empty();
    let device_type = $("#magazine").val();
    let page = parseInt($("#currentpage").text());
    let input_type_id = $("#input_type").val();
    let limit = $("#limit").val();
    let user_ids = $("#user").val();
    let device_ids = $("#list__device").val();
    $.ajax({
        type: "POST",
        url:  COMPONENTS_PATH+"/archive/archive-table.php",
        data: {device_type: device_type, user_ids: user_ids, device_ids: device_ids, page: page, input_type_id: input_type_id, limit: limit},
        success: function(data) {
            let archiveData = JSON.parse(data)[0];
            let nextPageAvailable = JSON.parse(data)[1];
            $("#nextpage").prop('disabled', !nextPageAvailable);
            $("#previouspage").prop('disabled', (page==1));
            for(const entry of Object.entries(archiveData))
            {
                let row = entry[1];
                let tableRow = `
                <tr>
                    <td>`+row[0]+`</td>
                    <td>`+row[1]+`</td>
                    <td>`+row[2]+`</td> 
                    <td>`+row[3]+`</td>
                    <td>`+row[4]+`</td>
                </tr>`
                $("#archiveTable").append(tableRow);
            }
        }
    });

}

$("#magazine, #user, #list__device, #input_type").change(function(){
    $("#currentpage").text(1);
    loadarchive();
});

$("#magazine").change(function(){
    $("#list__device").empty();
    $('#list__'+this.value+' option').clone().appendTo('#list__device');
    $('#user, #list__device, #input_type, #limit, #previouspage, #nextpage, #clearselect').prop("disabled", false);
    $('.selectpicker').selectpicker('refresh');
});

$("#user").change(function(){
    $('#list__device').prop("disabled", false);
});

$('#limitTable').submit(function(e){
    e.preventDefault();
    $("#currentpage").text(1);
    loadarchive();
});


$("#previouspage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    if (page != 1) {
        page--;
        $("#currentpage").text(page);
        loadarchive();
    }
});

$("#nextpage").click(function () {
    $("#nextpage, #previouspage").prop('disabled', true);
    let page = parseInt($("#currentpage").text());
    page++;
    $("#currentpage").text(page);
    loadarchive();
});