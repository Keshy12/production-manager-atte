<script type="text/template" data-template="vendorPartRowTemplate">
    <!--
        Reference template for a VendorPart row. The actual rendering happens
        in vendor-parts-view.js via the renderRow() template literal — this
        file documents the column shape so future changes stay in sync.

        Variables interpolated by renderRow():
          ${id}             int
          ${rowClass}       'vp-row--inactive table-secondary' for inactive
                            rows, '' otherwise. Inactive state is conveyed
                            entirely by the row tint — no badge column.
          ${artykulCell}    partName + (small/muted) merged-or-separate
                            vendor/producer part numbers:
              - vn && pn && vn === pn  →  'Nr dost./prod.: <bold>'
              - otherwise              →  'Nr dost.: <bold>' and/or 'Nr prod.: <bold>'
          ${dostawcaCell}   vendorName + optional producerName sub-line
                            (sub-line omitted when producerName is empty)
          ${unitName}       html-escaped (or '—' if null)
          ${packsHtml}      small inline badges of comma-separated pack sizes,
                            or '—' if the row has no packs recorded
          ${komentarzCell}  small/muted text with bi-journal-text icon;
                            truncated with ellipsis at 200 chars (full text
                            stays on the native title tooltip)
          ${editBtn}        <a class="btn btn-sm btn-warning" href="/atte_ms_new/admin/purchase/vendor-parts/edit?id=${id}">
                            — the dedicated edit page (see edit/vendor-part-edit-view.php).
                            Was a <button class="edit-vp-btn"> + modal in the previous design.

        Column order (matches <thead> in vendor-parts-view.php):
          ID | Artykuł | Dostawca | JM | Pełne opakowania | Komentarz | Akcje

        7 columns. colspan for in the empty-state row is also 7.
    -->
</script>