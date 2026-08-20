<!-- Send PO confirm modal -->
<div class="modal fade" id="sendPoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="send_po_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send"></i> Wyślij zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="send_po_body">
                Czy na pewno wysłać zamówienie?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmSendPo" class="btn btn-primary">Tak, wyślij</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm PO modal (with vendor_po_number input) -->
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

<!-- Cancel PO confirm modal -->
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
