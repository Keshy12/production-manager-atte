<!-- Edit Producer Modal -->
<div class="modal fade" id="editProducerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="editProducerForm">
                <input type="hidden" id="edit_producer_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj producenta</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_producer_name">Nazwa:</label>
                        <input type="text" class="form-control" id="edit_producer_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_producer_comment">Komentarz:</label>
                        <textarea class="form-control" id="edit_producer_comment" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Anuluj
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Zapisz zmiany
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Producer Confirm Modal -->
<div class="modal fade" id="toggleProducerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="toggle_producer_id">
            <input type="hidden" id="toggle_producer_is_active">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="toggle_producer_body">
                Czy na pewno chcesz zmienić status tego producenta?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmToggleProducer" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>
