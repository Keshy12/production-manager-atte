$("#startSync").click(function() {
    let $btn = $(this);
    let $loader = $("#syncLoader");
    let $result = $("#ajaxResult");
    
    $btn.prop('disabled', true).fadeOut();
    $loader.fadeIn();
    $result.empty();
    
    $.ajax({
        type: "POST",
        url: ROOT_DIR + "/src/cron/update-part-prices.php",
        success: function (data) {
            $loader.fadeOut(function() {
                $btn.fadeIn().prop('disabled', false);
                
                let alertHtml = `<div class="alert alert-success alert-dismissible fade show text-left" role="alert">
                    <h4 class="alert-heading">Synchronizacja zakończona!</h4>
                    <hr>
                    <pre style="white-space: pre-wrap; margin-bottom: 0; background: #f8f9fa; padding: 10px; border-radius: 4px;">` + data + `</pre>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>`;
                
                $result.append(alertHtml);
            });
        },
        error: function() {
            $loader.fadeOut(function() {
                $btn.fadeIn().prop('disabled', false);
                
                let alertHtml = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Błąd!</strong> Nie udało się połączyć z serwerem lub wystąpił błąd podczas synchronizacji.
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>`;
                
                $result.append(alertHtml);
            });
        }
    });
});
