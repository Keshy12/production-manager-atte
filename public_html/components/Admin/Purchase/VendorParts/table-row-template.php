<script type="text/template" data-template="vendorPartRowTemplate">
    <!--
        Reference template for a VendorPart row. The actual rendering happens
        in vendor-parts-view.js via renderRow() — this file documents the
        column shape so future changes stay in sync.

        Column order (matches <thead> in vendor-parts-view.php):
          ID | Artykuł | Dostawca / Producent | Opakowania | Status | Akcje

        Cells:
          id                int, grey tabular figures
          rowClass          'vp-row--inactive table-secondary' for inactive rows,
                            '' otherwise
          Artykuł           .vp-code  vendor part number (monospace, primary line)
                            .vp-sub   catalogue part name (muted line)
                            .vp-code--sub  'prod.: <no>' — only when the producer
                            number differs from the vendor number
                            + bi-journal-text icon with the comment in title=
                            when a comment exists (no comment column)
          Dostawca / Prod.  .vp-name vendor name + .vp-sub producer name
                            (sub-line omitted when producerName is empty)
          Opakowania        .vp-pack-badge per pack size + unit name once,
                            or '—' when no packs are recorded
          Status            badge-success 'Aktywny' / muted .vp-badge-off
                            'Nieaktywny'
          Akcje             <a class="btn btn-sm btn-warning"
                               href="/atte_ms_new/admin/purchase/vendor-parts/edit?id=${id}"
                               title="Edytuj artykuł #id" aria-label="...">
                            with a bi-pencil icon only. Dedicated edit page:
                            see edit/vendor-part-edit-view.php.

        CSS lives in vendor-parts-view.php, scoped to #vpTable.
        6 columns; colspan of the "Ładowanie…" row in the view is also 6.
    -->
</script>
