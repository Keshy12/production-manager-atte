<!-- Receipt info modal (read-only) -->
<div class="modal fade" id="receiptInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-eye"></i> Szczegóły przyjęcia
                </h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="receiptInfoHeader" class="mb-3"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-light">
                        <tr>
                            <th>VendorPartNo</th>
                            <th>Part</th>
                            <th>Producent</th>
                            <th>JM</th>
                            <th>Ilość&nbsp;przyjęta</th>
                            <th>JM&nbsp;docelowe</th>
                            <th>Magazyn</th>
                            <th>Komentarz</th>
                        </tr>
                        </thead>
                        <tbody id="receiptInfoItems"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>
