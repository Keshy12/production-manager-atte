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
                <th>Status</th>
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
                    <th scope="col" style="width: 50%;">Komponent</th>
                    <th scope="col" style="width: 15%;">Ilość</th>
                    <th scope="col" style="width: 35%;">Źródła</th>
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
            ${quantityDisplay}
        </td>
        <td class="statusCell">
            <span class="badge ${statusBadgeClass}">${statusText}</span>
        </td>
    </tr>
</script>

<script type="text/template" data-template="resultComponentTableRow_template">
    <tr>
        <td class="componentInfo">
            <b>${deviceName}</b><br>
            <small class="text-muted">${deviceDescription}</small>
        </td>
        <td class="text-center">
            <strong>${transferQty}</strong>
        </td>
        <td class="text-center">
            ${sourcesDisplay}
        </td>
    </tr>
</script>