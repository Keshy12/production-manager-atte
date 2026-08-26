$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Producers/";

    function showAlert(message, type) {
        if(!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('#alertContainer').html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    function getAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    // Add producer
    $('#addProducerForm').on('submit', function(e) {
        e.preventDefault();
        const name = $('#producer_name').val().trim();
        const comment = $('#producer_comment').val().trim();
        if(!name) { showAlert('Nazwa producenta jest wymagana', 'warning'); return; }
        postAjax('producer-add.php', { name: name, comment: comment })
            .done(function(r) {
                if(r.success) {
                    showAlert('Producent dodany pomyślnie', 'success');
                    location.reload();
                } else {
                    showAlert(r.error || 'Wystąpił błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Open edit modal
    $(document).on('click', '.edit-producer-btn', function() {
        const id = $(this).data('id');
        getAjax('producer-get.php', { id: id })
            .done(function(r) {
                if(r.success) {
                    $('#edit_producer_id').val(r.producer.id);
                    $('#edit_producer_name').val(r.producer.name);
                    $('#edit_producer_comment').val(r.producer.comment || '');
                    $('#editProducerModal').modal('show');
                } else {
                    showAlert(r.error || 'Błąd ładowania danych', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Submit edit
    $('#editProducerForm').on('submit', function(e) {
        e.preventDefault();
        const id = parseInt($('#edit_producer_id').val(), 10);
        const name = $('#edit_producer_name').val().trim();
        const comment = $('#edit_producer_comment').val().trim();
        if(!name) { showAlert('Nazwa jest wymagana', 'warning'); return; }
        postAjax('producer-update.php', { id: id, name: name, comment: comment })
            .done(function(r) {
                if(r.success) {
                    $('#editProducerModal').modal('hide');
                    showAlert('Zmiany zapisane', 'success');
                    location.reload();
                } else {
                    showAlert(r.error || 'Błąd zapisu', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Open toggle confirm
    $(document).on('click', '.toggle-producer-btn', function() {
        const id = $(this).data('id');
        const isActive = $(this).data('is-active') == 1;
        const name = $(this).data('name');
        $('#toggle_producer_id').val(id);
        $('#toggle_producerIsActive').val(isActive ? '1' : '0');
        const verb = isActive ? 'wyłączyć' : 'włączyć';
        $('#toggle_producer_body').html(
            'Czy na pewno chcesz <b>' + verb + '</b> producenta <b>'
            + $('<div>').text(name).html() + '</b>?'
        );
        $('#toggleProducerModal').modal('show');
    });

    // Confirm toggle
    $('#confirmToggleProducer').on('click', function() {
        const id = parseInt($('#toggle_producer_id').val(), 10);
        const isActive = $('#toggle_producerIsActive').val() === '1';
        postAjax('producer-toggle-active.php', { id: id, isActive: isActive ? 1 : 0 })
            .done(function(r) {
                if(r.success) {
                    $('#toggleProducerModal').modal('hide');
                    showAlert(r.message || 'Status zmieniony', 'success');
                    location.reload();
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });
});
