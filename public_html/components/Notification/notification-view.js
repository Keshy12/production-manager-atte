const queriesAffected = parseInt($('#queriesAffected').text());
const notificationId = parseInt($('#notificationId').data('id'));

// Using fetch because ajax can't omit credentials
// Other tabs are blocked when resolvement is in progress
async function postData(url = "", data = {}) {
    const response = await fetch(url, {
        method: "POST",
        credentials: "omit",
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(data)
    });
    return response.text();
}

var duringFetch = false;

var checkPercent = function(){
    if(!duringFetch){
        duringFetch = true;
        postData( COMPONENTS_PATH+"/notification/count-affected-queries.php", { id: notificationId })
    .then((data) => {
            let remaining = queriesAffected-data;
            let percentCompleted = remaining/queriesAffected*100;
            let roundedPercent = Math.round((percentCompleted + Number.EPSILON) * 100) / 100
            $("#percentCompleted").text(roundedPercent + "%");
            $("#progressBar").width(roundedPercent + "%");
            $(document).prop( 'title' , '['+roundedPercent+'%]Ładowanie...' );
            duringFetch = false;
        })
            .catch((err) => {
                $("#percentCompleted").html(err);
                duringFetch = false;
            });
    }
};

$("#tryResolve").click(function(){
    let interval = setInterval(checkPercent,5000);
    $(this).prop("disabled", true);
    $("#spinnerResolve").show();
    $(document).prop( 'title' , 'Ładowanie...' );
    postData(COMPONENTS_PATH+"/notification/resolve-notification.php", { id: notificationId })
.then((data) => {
        $("#result").html(data);
        $("#spinnerResolve").hide();
        $("#tryResolve").prop("disabled", false);
        clearInterval(interval);
        duringFetch = false;
        checkPercent();
        $(document).prop( 'title' , 'Powiadomienie' );

    })
        .catch((err) => {
            $("#result").html(err);
            $("#spinnerResolve").hide();
            $("#tryResolve").prop("disabled", false);
            clearInterval(interval);
            duringFetch = false;
            checkPercent();
            $(document).prop( 'title' , 'Powiadomienie' );
        });

});