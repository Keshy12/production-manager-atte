<script type="text/template" data-template="warehouseTableItem">
<tr class="tablerowcollapse" data-toggle="collapse" data-target=".collapse${key}" href=".collapse${key}">
    <td style="width: 30%"> ${componentName} <br/>
        <small class="text-wrap">
            ${componentDescription}
        </small>
    </td>
    <td style="width: 30%">
        ${sumType1}
    </td>
    <td style="width: 30%">
        ${sumType2}
    </td>
    <td style="width: 10%">
        ${sumAll}
    </td>
</tr>
<tr>
    <td class="tdcollapse"></td>
    <td class="tdcollapse">
        <div class="collapse${key} collapse mx-1 mainWarehouses"><small class="text-muted">Wyłączone (czerwone) magazyny nie wliczają się do sumy.</small><br></div>
    </td>
    <td class="tdcollapse">
        <div class="collapse${key} collapse mx-1 otherWarehouses"><small class="text-muted">Wyłączone (czerwone) magazyny nie wliczają się do sumy.</small><br></div>
    </td>
    <td class="tdcollapse"></td>
</tr>
</script>