<!-- Edit line item modal -->
<div class="modal fade" id="editRfqItemModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="editRfqItemForm">
                <input type="hidden" id="edit_rfq_item_id">
                <input type="hidden" id="edit_rfq_item_rfq_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj pozycję</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Artykuł u dostawcy:</label>
                        <select id="edit_rfq_item_vendor_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                            <option value="">Wybierz...</option>
                        </select>
                        <input type="hidden" id="edit_rfq_item_vendor_part_id">
                        <input type="hidden" id="edit_rfq_item_quantity_unit_id">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_rfq_item_quantity">Ilość:</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control" id="edit_rfq_item_quantity" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_rfq_item_unit_price">Cena:</label>
                                <input type="number" step="0.0001" min="0" class="form-control" id="edit_rfq_item_unit_price">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="edit_rfq_item_currency">Waluta:</label>
                                <select class="form-control" id="edit_rfq_item_currency">
                                    <option value="PLN">PLN</option>
                                    <option value="EUR">EUR</option>
                                    <option value="USD">USD</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_rfq_item_comment">Komentarz:</label>
                        <textarea class="form-control" id="edit_rfq_item_comment" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete line item confirm modal -->
<div class="modal fade" id="deleteRfqItemModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="delete_rfq_item_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="delete_rfq_item_body">
                Czy na pewno usunąć pozycję?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmDeleteRfqItem" class="btn btn-danger">Tak, usuń</button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel RFQ confirm modal (shared with parent) -->
<div class="modal fade" id="cancelRfqModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="cancel_rfq_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Anuluj zapytanie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="cancel_rfq_body">
                Czy na pewno anulować zapytanie?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmCancelRfq" class="btn btn-danger">Tak, anuluj</button>
            </div>
        </div>
    </div>
</div>

<!-- Send RFQ confirm modal (shared with parent) -->
<div class="modal fade" id="sendRfqModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="send_rfq_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send"></i> Wyślij zapytanie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="send_rfq_body">
                Czy na pewno wysłać zapytanie?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmSendRfq" class="btn btn-primary">Tak, wyślij</button>
            </div>
        </div>
    </div>
</div>
