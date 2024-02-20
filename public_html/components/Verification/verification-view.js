let verifyCard = $('script[data-template="verifyCard"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function renderCards(deviceTypes) {
    for (let deviceType in deviceTypes) {
        $("#container").append("<hr><h1 class='text-center'>"+deviceType.toUpperCase()+"</h1><hr>");
        $('<div class="justify-content-center d-flex flex-wrap">').appendTo("#container").append(deviceTypes[deviceType].map(function (item) {
            return verifyCard.map(render(item)).join('');
        }));
    }

}

$("#verifyType").change(function(){
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/verification/get-verification-values.php",
        data: {deviceType: this.value},
        success: function(data) {
            let result = JSON.parse(data);
            $("#container").empty();
            renderCards(result);
        }
    });
});

$(document).ready(function(){
   $("#verifyType").change();
});

$('body').on('click', ".verificationSubmit", function(){
    let $cardbody = $(this).parent().parent();
    let comment = $cardbody.find(".comment").val();
    let quantity = $cardbody.find(".quantity").val();
    let commissionId = $(this).attr("data-commissionid");
    let deviceType = $(this).attr("data-devicetype");
    let deviceId = $(this).attr("data-deviceid");
    let id = this.value;
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+'/verification/verify.php',
        data: {deviceType: deviceType, id: id, comment: comment, quantity: quantity, commissionId: commissionId, deviceId: deviceId},
        success: function(data)
        {
            console.log(data);
            $("#verifyType").change();
        }
    });
});

$('body').on('change', ".correct", function(){
    let $cardbody = $(this).parent().parent().parent();
    $cardbody.find(".quantity").prop("readonly", !($(this).prop('checked')))
});
