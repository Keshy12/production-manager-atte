<div id="transferResult" style="display: none;">
    <div class="d-flex justify-content-center align-items-center">
        <div class="alert alert-success w-50 text-center my-2" role="alert">
            Dane przesłane poprawnie!
        </div>
    </div>
    <div class="d-flex justify-content-center align-items-center">
        <div id="commissionResultTableContainer" class="w-50">
            <h4 class="text-center my-2">
                Utworzone zlecenia
            </h4>
            <table id="commissionTable" class="table table-bordered table-sm table-hover text-center">
                <thead>
                    <th>Odbiorca</th>
                    <th>Urządzenie</th>
                    <th>Laminat</th>
                    <th>Wersja</th>
                    <th>Ilość</th>
                </thead>
                <tbody id="commissionResultTBody"></tbody>
            </table>
        </div>
    </div>
    <div class="d-flex justify-content-center align-items-center">
        <div id="componentListResult w-50" class="mt-4">
            <h4 class="text-center my-4">
                Przekazane Komponenty
            </h4>
            <table class="table table-sm table-striped">
                <thead>
                    <tr class="text-center">
                        <th scope="col">Komponent</th>
                        <th scope="col">Ilość przekazywana</th>
                    </tr>
                </thead>
                <tbody class="text-center" id="componentResultTBody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/template" data-template="resultCommissionTableRow_template">
    <tr style="box-shadow: -7px 0px 0px 0px ${priorityColor}">
        <td>
            ${receivers}
        </td>
        <td class="deviceInfo">
            <b>${deviceName}</b><br>
            <small>${deviceDescription}</small>
        </td>
        <td class="laminateCell">
            ${laminate}
        </td>
        <td class="versionCell">
            ${version}
        </td>
        <td class="quantityCell">
            ${quantity}
        </td>
    </tr>
</script>

<script type="text/template" data-template="resultComponentTableRow_template">
    <tr>
        <td>
            <b>${deviceName}</b><br>
            <small>${deviceDescription}</small>
        </td>
        <td>
            ${transferQty}
        </td>
    </tr>
</script>
