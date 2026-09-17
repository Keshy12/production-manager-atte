<!--
    Modal szczegółów przyjęcia (tylko do odczytu). Wypełnia go
    receipts-view.js → openReceiptModal() z receipt-get.php.

    Kolumny tabeli odpowiadają 1:1 polom, które zwraca receipt-get.php
    dla każdej pozycji. Poprzednia wersja miała 8 nagłówków, ale JS
    renderował 7 komórek — nagłówek „JM docelowe" nie miał odpowiednika
    w danych, więc od tego miejsca cały wiersz był przesunięty o jedną
    kolumnę. Teraz nagłówki i komórki się zgadzają, a doszły dwa pola,
    które endpoint zwracał, a których nikt nie pokazywał:
    `orderedQty` (ile zamówiono) i `totalReceivedQty` (ile przyjęto
    łącznie na zamówieniu, ze wszystkich dostaw). Dzięki nim widać, czy
    to przyjęcie domknęło pozycję, czy była to dostawa częściowa.
-->
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
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th scope="col">Nr dostawcy</th>
                            <th scope="col">Nazwa</th>
                            <th scope="col">Producent</th>
                            <th scope="col">JM</th>
                            <th scope="col" class="text-right">Zamówiono</th>
                            <th scope="col" class="text-right">W tym dokumencie</th>
                            <th scope="col" class="text-right">Przyjęto łącznie</th>
                            <th scope="col">Magazyn</th>
                            <th scope="col">Komentarz</th>
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
