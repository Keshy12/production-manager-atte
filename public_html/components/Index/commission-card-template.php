<script type="text/template" data-template="commissionCard">
    <div class="card card${id} w-25 text-center m-4 ${cardClass}"
         style="box-shadow: -7px 0px 0px 0px ${color}; min-width: 360px;"
         ${groupedIdsAttr}>
        <div class="card-header">
            ${receiversButton}
            ${menuButton}
            <a data-toggle="popover"
               style="font-size: 1.25rem;"
               data-placement="top"
               data-content="${deviceDescription}">
                ${deviceName}
            </a>
            <br>
            <small>
                ${laminateInfo}
                ${versionInfo}
            </small>
            <br>
            ${groupBadge}
        </div>
        <div class="card-body">
            <table style="table-layout: fixed" class="table table-active table-bordered table-sm">
                <thead>
                <tr class="${tableClass}">
                    <th>Zlecono</th>
                    <th>Wyprodukowano</th>
                </tr>
                </thead>
                <tbody>
                <tr class="${tableClass}">
                    <td class="quantity">${quantity}</td>
                    <td class="quantityProduced">${quantityProduced}</td>
                </tr>
                ${productionLink}
                </tbody>
            </table>
            <table class="table table-active table-bordered table-sm">
                <thead>
                <tr class="${tableClass}">
                    <th>Dostarczono <br>${warehouseInfo}</th>
                </tr>
                </thead>

                <tbody>
                <tr class="${tableClass}">
                    <td class="quantityReturned">${quantityReturned}</td>
                </tr>
                ${returnInput}
                </tbody>
            </table>
            ${submitButton}
        </div>
        <div class="card-footer text-muted">
            Data zlecenia: ${timestampCreated}
        </div>
    </div>
</script>