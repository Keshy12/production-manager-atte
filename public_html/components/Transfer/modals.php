<div class="modal fade" id="deleteComponentRowModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Usuń komponent z transferu</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz usunąć tę pozycję?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="deleteFromTransfer" data-dismiss="modal" class="btn btn-primary">Usuń</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="transferWithoutCommissionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Przekaż komponenty bez z zlecenia</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy chcesz przekazać komponenty bez zlecenia?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="transferNoCommission" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="commissionWithoutTransferModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Zlecenie bez transferu</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy chcesz utworzyć zlecenie bez przekazania komponentów?<br>
                Oba wybrane magazyny do transferu są te same.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="commissionNoTransfer" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="duplicateComponentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Komponent już istnieje</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Ten komponent już znajduje się w tym zleceniu.</p>
                <p>Zamiast dodawać nową pozycję, zmień ilość w istniejącej pozycji.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">Rozumiem</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="submitExplanationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Jak interpretować widok transferu</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h6>Objaśnienie kolorów i widoczności:</h6>
                <ul>
                    <li><strong style="color: #007bff; border-left: 3px solid #007bff; padding-left: 10px;">Niebieska linia po lewej stronie</strong> - komponenty dodane ręcznie</li>
                    <li><strong>Komponenty bez niebieskiej linii</strong> - komponenty wymagane przez zlecenia (z BOM)</li>
                </ul>

                <h6 class="mt-3">Widoczność komponentów:</h6>
                <ul>
                    <li><strong style="color: #007bff; border-left: 3px solid #007bff; padding-left: 10px;">Komponenty z niebieską linią</strong> - zawsze widoczne, niezależnie od stanu zwinięcia zleceń</li>
                    <li><strong>Pozostałe komponenty</strong> - ukryte gdy zlecenia są zwinięte, ale widoczne w podsumowaniu</li>
                    <li><strong>Niebieskie komponenty w sekcji "Podsumowanie"</strong> - transferowane bez przypisania do konkretnego zlecenia</li>
                </ul>

                <h6 class="mt-3">Podsumowanie komponentów:</h6>
                <p>Pokazuje łączne ilości wszystkich komponentów wymaganych przez zlecenia (bez ręcznie dodanych).</p>

                <h6 class="mt-3">Przesyłanie transferu:</h6>
                <p>Przed przesłaniem transferu:</p>
                <ul>
                    <li>Sekcja "Podsumowanie komponentów" musi być rozwinięta</li>
                    <li>Wszystkie zlecenia zostaną automatycznie zwinięte</li>
                    <li>Sprawdź łączne ilości w podsumowaniu przed zatwierdzeniem</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">Rozumiem</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="summaryCollapsedModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Podsumowanie jest zwinięte</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Przed przesłaniem transferu musisz rozwinąć sekcję <strong>"Podsumowanie komponentów"</strong>.</p>
                <p>Dzięki temu będziesz mógł sprawdzić łączne ilości wszystkich komponentów przed zatwierdzeniem transferu.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">Rozumiem</button>
            </div>
        </div>
    </div>
</div>