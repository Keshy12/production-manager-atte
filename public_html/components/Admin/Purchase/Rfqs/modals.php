<!-- Cancel RFQ confirm modal -->
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

<!-- Send RFQ confirm modal -->
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
