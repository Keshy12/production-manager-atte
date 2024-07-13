$("#passwordForm").submit(function(e) {
    $("#resultMessage").hide();
    e.preventDefault(); // avoid to execute the actual submit of the form.
    let form = $(this);
    let actionUrl = form.attr('action');
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: form.serialize(), // serializes the form's elements.
        success: function(data)
        {
            let result = JSON.parse(data);
            let changeSuccessful = result[0];
            let resultMessage = result[1];
            $("#resultMessage").empty();
            $("#resultMessage").removeClass("alert-success").addClass("alert-danger");
            if(changeSuccessful) {
                $("#resultMessage").removeClass("alert-danger").addClass("alert-success");
                // clear the form
                form[0].reset();
            }
            $("#resultMessage").text(resultMessage);
            $("#resultMessage").show();
        }
    });
});