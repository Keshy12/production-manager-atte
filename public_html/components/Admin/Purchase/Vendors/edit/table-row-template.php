<script type="text/template" data-template="vendorRowTemplate">
    <!--
        Reference template for a Vendor row. The actual rendering happens
        in vendors-view.js via the renderRow() function — this file
        documents the column shape so future changes stay in sync.

        Visual language mirrors the email-drafts list: ID muted + tabular,
        name semi-bold, grey badge for inactive (never red), single
        icon-only warning button for edit.

        Fields used by renderRow():
          id               int
          rowClass         'vr-row--inactive table-secondary' for inactive
                           rows, '' otherwise
          name             vendor name (.vr-name) — first line of the
                           "Dostawca" cell
          address          muted second line (.vr-sub), clipped, full text
                           in title; line omitted when address and comment
                           are both empty
          comment          not a column: a bi-journal-text icon after the
                           address, full text in its title
          leadTimeDays     '<n> dni', or muted em-dash when null
          supplierCount    person icon + number  } one "Powiązania" cell;
          vendorPartCount  box icon + number     } 0 shown dimmed
          isActive         .badge-success 'Aktywny' / .vr-badge-off 'Nieaktywny'
          edit button      <a class="btn btn-sm btn-warning"> with bi-pencil,
                           title and aria-label

        Not shown (still returned by vendor-list.php): additionalData,
        createdAt. createdAt remains available through the date filter.

        Column order (matches <thead> in vendors-view.php):
          ID | Dostawca | Lead time | Powiązania | Status | Akcje

        6 columns. colspan for the loading / error row is also 6.
    -->
</script>
