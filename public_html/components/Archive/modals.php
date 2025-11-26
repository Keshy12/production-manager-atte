<!-- Cancel Transfers Confirmation Modal -->
<div class="modal fade" id="cancelTransfersModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Anuluj transfery</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Uwaga!</strong> Czy na pewno chcesz anulować zaznaczone transfery?
                    <br>Tej operacji nie można cofnąć.
                </div>

                <!-- Group cancellation warning (hidden by default) -->
                <div id="groupCancellationWarning" class="alert alert-info" style="display: none;">
                    <h6><i class="bi bi-info-circle"></i> Anulacja grupy transferów</h6>
                    <div id="groupCancellationDetails"></div>
                </div>

                <!-- Incomplete group warning (hidden by default) -->
                <div id="incompleteGroupWarning" class="alert alert-danger" style="display: none;">
                    <h6><i class="bi bi-exclamation-triangle-fill"></i> Niekompletna grupa transferów</h6>
                    <p id="incompleteGroupMessage"></p>
                    <button type="button" id="loadMissingTransfers" class="btn btn-sm btn-primary">
                        <i class="bi bi-download"></i> Załaduj brakujące transfery
                    </button>
                </div>

                <!-- Manual unchecked warning (hidden by default) -->
                <div id="manualUncheckedWarning" class="alert alert-warning" style="display: none;">
                    <h6><i class="bi bi-exclamation-triangle"></i> Transfery odznaczone</h6>
                    <p id="manualUncheckedMessage"></p>
                </div>

                <h6 class="mb-3">Podsumowanie anulacji:</h6>

                <div class="mb-3">
                    <strong>Liczba zaznaczonych transferów:</strong>
                    <span class="badge badge-danger badge-pill ml-2" id="cancelCount">0</span>
                </div>

                <!-- Collapsible group structure container -->
                <div id="groupedCancellationView" style="display: none;">
                    <!-- Will be populated with grouped structure via JavaScript -->
                </div>

                <!-- Traditional flat table view -->
                <div id="flatCancellationView" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th></th>
                                <th>Użytkownik</th>
                                <th>Magazyn</th>
                                <th>Urządzenie</th>
                                <th>Typ operacji</th>
                                <th>Ilość</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody id="cancelSummaryBody">
                            <!-- Will be populated via JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmCancelTransfers" class="btn btn-danger">
                    <i class="bi bi-x-circle"></i> Potwierdź anulowanie
                </button>
            </div>
        </div>
    </div>
</div>
