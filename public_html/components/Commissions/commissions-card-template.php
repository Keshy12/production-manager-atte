<script type="text/template" data-template="commissionCard">
<div class="card w-25 text-center m-4 ${class}" style="box-shadow: -7px 0px 0px 0px ${color}; min-width: 360px;">
    <div class="card-header">
        <button type="button" style="float: left;" class="close" tabindex="0" role="button" data-toggle="popover" data-trigger="focus" title="Zlecono:" data-html="true" data-content="
        Z: <b>${magazineFrom}</b><br>
        Dla: <b>${magazineTo}</b>">
            <img src="http://<?=BASEURL?>/public_html/assets/img/warehouse.svg" style="width: 20px;">
        </button>
        <span ${isHidden}>
        <button type="button" class="close" id="dropdownMenuButton" data-toggle="dropdown">
            <svg style="width: 20px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><!--! Font Awesome Pro 6.2.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM0 256c0-17.7 14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32-14.3-32-32zM448 416c0 17.7-14.3 32-32 32H32c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32z"></path></svg>
        </button>
        <div class="dropdown-menu">
            <a class="dropdown-item editCommission" data-id="${id}" data-submagazine="${magazineTo}" data-receivers="${receivers}" data-priority="${priority}">Edytuj zlecenie</a>
            <a class="dropdown-item cancelCommission" data-id="${id}">Anuluj zlecenie</a>
        </div>
        </span>
        <a data-toggle="popover" style="font-size: 1.25rem;" data-placement="top" data-content="${deviceDescription}">${deviceName}</a>
        <br><small>${deviceLaminateAndVersion}</small>
        <br>
        <span class="${class2}">Zlecono dla: <b>${receiversName}</b></span>
    </div>
    <div class="card-body">
        <table style="table-layout: fixed" class="table table-bordered table-sm">
            <thead>
                <tr class="${class3}">
                    <th>Zlecono</th>
                    <th>Wyprodukowano</th>
                </tr>
            </thead>
            <tbody>
                <tr class="${class3}">
                    <td class="quantity">${quantity}</td>
                    <td class="quantityProduced">${quantityProduced}</td>
                </tr>
            </tbody>
        </table>
        <table class="table table-bordered table-sm">
            <thead>
                <tr class="${class3}">
                    <th>Dostarczono:</th>
                </tr>
            </thead>
            <tbody>
                <tr class="${class3}">
                    <td class="quantityReturned">${quantityReturned}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer ${class2}">
        Data zlecenia: ${timestampCreated}
    </div>
</div>
</script>
