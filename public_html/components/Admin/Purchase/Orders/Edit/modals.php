<!-- Edit PO line item modal -->
<div class="modal fade" id="editOrderItemModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="editOrderItemForm">
                <input type="hidden" id="edit_po_item_id">
                <input type="hidden" id="edit_po_item_po_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj pozycję</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Artykuł u dostawcy:</label>
                        <select id="edit_po_item_vendor_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                            <option value="">Wybierz...</option>
                        </select>
                        <input type="hidden" id="edit_po_item_vendor_part_id">
                        <input type="hidden" id="edit_po_item_quantity_unit_id">
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_po_item_quantity">Ilość:</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control" id="edit_po_item_quantity" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_po_item_unit_price">Cena jedn.:</label>
                                <input type="number" step="0.0001" min="0" class="form-control" id="edit_po_item_unit_price">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_po_item_currency">Waluta:</label>
                                <select class="form-control" id="edit_po_item_currency">
                                    <option value="PLN">PLN</option>
                                    <option value="EUR">EUR</option>
                                    <option value="USD">USD</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_po_item_comment">Komentarz:</label>
                        <textarea class="form-control" id="edit_po_item_comment" rows="2"></textarea>
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

<!-- Delete PO line item confirm modal -->
<div class="modal fade" id="deleteOrderItemModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="delete_po_item_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="delete_po_item_body">Czy na pewno usunąć pozycję?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmDeleteOrderItem" class="btn btn-danger">Tak, usuń</button>
            </div>
        </div>
    </div>
</div>

<!-- Send / Confirm / Cancel modals (shared with parent list page) -->
<div class="modal fade" id="sendPoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="send_po_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send"></i> Wyślij zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="send_po_body">Czy na pewno wysłać zamówienie?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmSendPo" class="btn btn-primary">Tak, wyślij</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmPoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="confirm_po_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> Potwierdź zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="confirm_po_body">Czy na pewno oznaczyć zamówienie jako potwierdzone?</div>
                <div class="form-group mt-3">
                    <label for="confirm_vendor_po_number">Numer potwierdzenia u dostawcy:</label>
                    <input type="text" class="form-control" id="confirm_vendor_po_number" placeholder="np. VENDOR-12345">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmConfirmPo" class="btn btn-info">Tak, potwierdź</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelPoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="cancel_po_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Anuluj zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="cancel_po_body">
                Czy na pewno anulować zamówienie?
                <div class="text-warning mt-2">
                    <i class="bi bi-info-circle"></i> Jeśli są powiązane faktury lub dostawy, mogą wymagać ręcznego rozliczenia.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmCancelPo" class="btn btn-danger">Tak, anuluj</button>
            </div>
        </div>
    </div>
</div>
