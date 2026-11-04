# Plan — Invoice-driven goods reception (`/purchase/receipts`)

Status: **IMPLEMENTED 2026-10-31** (phases 1–6; decisions Q1–Q8 in §10, all
recommended defaults accepted). The text below is the original plan and is
kept as the design record. Items marked **[Q#]** point to the decision
recorded in §10.

**Deviations from the plan as built:**
- A **13th endpoint**, `delivery-list.php` (lists drafts / posted+cancelled for
  the Robocze / Historia tabs), plus a shared helper `delivery-common.php`
  (admin/method guards, JSON helpers, error mapping) — §7 listed 12 endpoints.
- The read endpoints (`delivery-vendors.php`, `delivery-open-items.php`,
  `delivery-part-search.php`, `delivery-get.php`, `delivery-list.php`) are
  **POST**, not GET: the repo `.htaccess` blocks GET to component files.
  (The „(GET)" marks in §7 are superseded.)
- The post-summary „Zamknij zamówienie" action on the delivery page calls
  `receipt-close-po.php` directly (→ `closePoFromReceive()`); no new endpoint.
- PO state on the delivery paths (`postDelivery` / `cancelPostedDelivery`) is
  derived **per line** (`deriveDeliveryPoState`): `received` only when every
  PO item is complete, so an over-delivery on one line cannot mask a shortfall
  on another (§5/§6 described a sum-based rule).
- Phase 6 removed `receive-page.php/.js`, `receive-form.php`, `receipt-po-get.php`,
  `receipt-create.php`, `receipt-accept.php`; the route
  `purchase/receipts/receive` now redirects to
  `/purchase/receipts/new?vendor_id=<vendor of po_id>`. `receipts.css` is no
  longer loaded by any page.

---

## 1. Problem

Today the system receives goods **one PO at a time**:
`/purchase/receipts/receive?po_id=N` → step 1 „Akceptuję” (`acceptReceiptMetadata`,
`confirmed → in_progress`) → step 2 enter quantities per PO line → `createReceipt()`.

In reality a vendor ships **one package / one invoice** that mixes lines from
several open POs (and sometimes the same part from two POs). The operator then
has to split the invoice across several receive pages by hand.

## 2. Target flow (operator's view)

1. Open `/purchase/receipts` → button **„Nowe przyjęcie”**.
2. **Pick a vendor** (only vendors that have open POs are listed first; all
   active vendors are available) [Q5].
3. Fill the **document header**: vendor invoice/WZ number, document date,
   receipt date (default today), target magazine (default for all lines),
   currency (default = vendor `default_currency`), comment.
4. **Enter invoice lines one by one** in the order printed on the invoice:
   - article (search scoped to the vendor's catalogue: `vendor_part_no`,
     `producer_part_no`, part name),
   - quantity,
   - unit price (net, in document currency).
   Each line is matched **immediately** against open PO lines. The UI shows
   right below the line which PO(s) it will fill, for example
   `PO/12/2026 — 300 szt. (zamknie pozycję)`, `PO/15/2026 — 200 szt. (zostanie 800)`.
5. The operator can **override** the automatic split of a line (choose a
   different PO, change the split quantities).
6. Lines with problems are flagged: price ≠ PO price, quantity over the
   remaining amount, article not on any open PO [Q2][Q3].
7. **„Zatwierdź przyjęcie”** → one transaction posts the whole document:
   stock enters the magazine, PO lines get `quantity_received`, every touched PO
   changes state (`partially_received` / `received`).
8. A **summary** screen lists the touched POs, their progress and the lines
   still missing. Per PO: „Zostaw otwarte” / „Zamknij zamówienie” (this reuses
   the existing `closePoFromReceive`).

The lines are stored as a **draft on the server** as they are typed (see §4.1), so a
long invoice survives a page reload or a break [Q1].

## 3. Domain model

```
purchase__delivery  (NEW, one per vendor document = invoice / WZ)
 ├── purchase__delivery_line  (NEW, one per invoice line, as typed)
 │     └── purchase__delivery_allocation (NEW, line → PO item split, 0..n)
 └── purchase__order_receipt  (EXISTING, generated on post: one per touched PO)
       └── purchase__order_receipt_item (EXISTING, one per allocation)
```

**Why keep `purchase__order_receipt`:** it becomes the *per-PO projection*
of a delivery. Everything that already reads it keeps working unchanged:
the history tab, the receipt detail modal (`receipt-get.php`), the PO edit
page, the documents list progress, the `closePoAsReceived` guard and
`OrderReceiptRepository::delete()` guards. The new tables add the
cross-PO "document" layer above it. They do not replace it.

## 4. Database changes

All changes go into a new idempotent migration script
`src/cron/migrate-purchase-delivery-schema.php` (same style as
`migrate-to-procurement-schema.php`), plus an update of `atte_ms_struct.sql`
and `docs/data/DATABASE.md` §4j.

### 4.1 `purchase__delivery` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `vendor_id` | INT NOT NULL FK→`list__vendor` | one vendor per document |
| `state` | ENUM(`draft`,`posted`,`cancelled`) DEFAULT `draft` | `draft` = lines being typed; `posted` = stock booked |
| `document_type` | ENUM(`invoice`,`wz`) DEFAULT `invoice` | [Q6] |
| `vendor_document_number` | VARCHAR(100) NULL | invoice / WZ number; required on post [Q6] |
| `document_date` | DATE NULL | date printed on the invoice |
| `grn_number` | VARCHAR(64) NULL | allocated on post from `purchase__number_counter` type `grn` (same counter as today) |
| `currency` | VARCHAR(8) NOT NULL DEFAULT 'PLN' | |
| `default_sub_magazine_id` | INT NULL FK→`magazine__list` | header default; each line can override it |
| `received_at` | DATETIME NULL | stamped on post (back-dating rules same as `createReceipt`) |
| `transfer_group_id` | INT NULL FK | one transfer group per delivery |
| `created_by` / `posted_by` | INT FK→`user` | |
| `created_at` / `posted_at` / `updated_at` | DATETIME | |
| `comment` | TEXT NULL | |

Indexes: `(vendor_id, state)`. Optional unique key on
`(vendor_id, vendor_document_number)` blocks booking the same invoice twice.
Recommended: a soft warning, not a hard UNIQUE, because corrections reuse the number.

### 4.2 `purchase__delivery_line` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `delivery_id` | INT FK ON DELETE CASCADE | |
| `line_no` | INT | order on the invoice |
| `vendor_part_id` | INT FK→`list__vendor_part` (RESTRICT) | must belong to `delivery.vendor_id` |
| `quantity` | DECIMAL(30,10) | in the PO line unit (`quantity_unit_id`) [Q7] |
| `unit_price` | DECIMAL(30,10) NULL | invoice net price per unit |
| `sub_magazine_id` | INT NULL FK | NULL = header default |
| `inventory_parts_id` | INT NULL | set on post. It links the line to the stock row it booked (for cancel/reversal) |
| `comment` | TEXT NULL | |

### 4.3 `purchase__delivery_allocation` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `delivery_line_id` | INT FK ON DELETE CASCADE | |
| `po_item_id` | INT FK→`purchase__order_item` (RESTRICT) | |
| `quantity` | DECIMAL(30,10) | part of the line assigned to that PO line |
| `is_manual` | TINYINT(1) DEFAULT 0 | 1 = the operator overrode the auto-split, so auto re-match must not touch it |

Invariant: `SUM(allocation.quantity) ≤ line.quantity`. The rest is
**unallocated surplus** [Q3].

### 4.4 Changes to existing tables

| Table | Change | Reason |
|---|---|---|
| `purchase__order_receipt` | `+ delivery_id INT NULL FK→purchase__delivery` | link the per-PO projection back to its document; NULL for legacy rows |
| `purchase__order_receipt_item` | `+ delivery_allocation_id INT NULL FK` | 1:1 traceability allocation → receipt item |
| `purchase__order_item` | none | `quantity_received` stays the running total |
| `ref__transfer_group_types` | `+ ('purchase_delivery', 'Przyjęcie dostawy {grn} — {vendor}')` | one transfer group per document instead of per PO |
| `inventory__input_type` | reuse `Przyjęcie z zamówienia PO` | no change |
| `purchase__order.state` ENUM | keep `in_progress` value (legacy rows) — no longer produced [Q4] | |

### 4.5 Legacy data

- No backfill is required. Old receipts keep `delivery_id = NULL` and still
  show in history exactly as now.
- Leftover **draft** receipts (header row with zero items, created by
  `acceptReceiptMetadata`) get deleted by the migration. POs left in
  `in_progress` with no posted receipt go back to `confirmed`, and those with
  receipts go to `partially_received` [Q4].

## 5. Matching algorithm (the core)

Runs server-side in a new class `Atte\Utils\Purchase\Order\DeliveryMatcher`
(`class-deliverymatcher.php`). It works on the **whole draft** each time, because
one line can use up capacity another line needs.

**Candidate PO lines** for a delivery line with `vendor_part_id = X`:
`purchase__order_item` where `vendor_part_id = X`, PO `vendor_id = delivery.vendor_id`,
PO state ∈ {`confirmed`, `in_progress`, `partially_received`}, and
`remaining = quantity − quantity_received − (allocated in THIS draft by other lines) > 0`.

**Ordering of candidates** (first match wins, greedy fill):
1. Price match first: `po_item.unit_price = line.unit_price` (within 0.0001 tolerance)
   [Q2]. Vendors usually invoice at the PO price, so this ties the line to the right PO.
2. Then the oldest due date first: `COALESCE(item.expected_delivery_date, po.expected_delivery_date)` ASC (NULLs last).
3. Then the oldest PO first: `po.confirmed_at` ASC, `po.id` ASC.

**Fill:** take `min(remaining_qty, candidate.remaining)` per candidate until the
line quantity runs out. Anything left after all candidates is **surplus** [Q3].

**Manual allocations** (`is_manual = 1`) are applied first and never re-split.
Auto allocations are rebuilt on every line add, edit or delete, so the
result is always consistent.

**Flags returned per line** (shown as badges, they do not block posting unless noted):
| Flag | Condition |
|---|---|
| `price_mismatch` | line price ≠ allocated PO item price (shows Δ % and both values) |
| `price_missing_on_po` | PO item `unit_price IS NULL` → the invoice price is written to the PO line [Q2] |
| `surplus` | unallocated quantity > 0 [Q3] |
| `not_on_po` | no candidate at all [Q3] |
| `currency_mismatch` | PO item currency ≠ document currency → **blocks** post |
| `duplicate_line` | same vendor part twice on the document → info only |

## 6. Backend (PHP)

### 6.1 New classes (`src/classes/Utils/Purchase/Order/`, then `composer dump-autoload`)

| File | Class | Responsibility |
|---|---|---|
| `class-delivery.php` | `Delivery` | entity (pure data, `array $row` ctor, B6 convention) |
| `class-deliveryline.php` | `DeliveryLine` | entity |
| `class-deliveryallocation.php` | `DeliveryAllocation` | entity |
| `class-deliveryrepository.php` | `DeliveryRepository` | CRUD header, `getDraftsByVendor`, `getAll` for history |
| `class-deliverylinerepository.php` | `DeliveryLineRepository` | CRUD lines + allocations (both tables, since allocations never exist without a line) |
| `class-deliverymatcher.php` | `DeliveryMatcher` | §5 algorithm. Read-only, returns allocation plan + flags |

### 6.2 New `PurchaseActionHandler` methods

| Method | What it does |
|---|---|
| `createDeliveryDraft(vendorId, userId, header)` | inserts `purchase__delivery` (`draft`) |
| `updateDeliveryHeader(deliveryId, header)` | draft only |
| `addDeliveryLine(deliveryId, vendorPartId, qty, price, subMag?, comment?)` | inserts line → runs matcher → rewrites auto allocations → returns full draft state |
| `updateDeliveryLine(...)` / `removeDeliveryLine(lineId)` | same, then re-match |
| `setLineAllocation(lineId, [[po_item_id, qty], ...])` | manual override (`is_manual = 1`), validates the PO items' vendor part, vendor, state and capacity |
| `postDelivery(deliveryId, userId)` | **one transaction**, see 6.3 |
| `discardDeliveryDraft(deliveryId)` | deletes the draft (cascade). Nothing was booked, so nothing needs reversing |
| `cancelPostedDelivery(deliveryId, userId)` | reversal, see 6.4 [Q8] |

### 6.3 `postDelivery` — transaction steps

1. Lock: `SELECT … FOR UPDATE` on the delivery row and on every candidate
   `purchase__order_item`, so two operators cannot post overlapping deliveries.
2. **Re-run the matcher** under the lock. If capacity changed since the draft was
   built, the auto allocations shift. If a *manual* allocation no longer fits,
   the post aborts with a Polish error.
3. Validate: state `draft`, ≥ 1 line, every line has a magazine (own or default), magazine
   active, no `currency_mismatch`, required header fields filled [Q6], surplus
   policy satisfied [Q3].
4. Allocate the GRN number (`allocateDocumentNumber('grn', 0)`) and set `received_at`.
5. Create **one** transfer group `purchase_delivery`, attrs `{delivery_id, vendor_id}`.
6. Per line: insert **one** `inventory__parts` row (+qty = full line qty,
   surplus included), with comment `Przyjęcie <GRN> / FV <nr> (<vendor_part_no>)`, and store
   `inventory_parts_id` on the line.
7. Group allocations by PO → per PO insert one `purchase__order_receipt`
   (`delivery_id`, `document_number = GRN`, `comment = "Nr dok. dostawcy: …"`) and
   one `purchase__order_receipt_item` per allocation (`delivery_allocation_id`).
8. Bump `purchase__order_item.quantity_received` per allocation.
9. Write the invoice price to PO lines with NULL `unit_price` [Q2].
10. Recompute the state of every touched PO with the rule `createReceipt` uses today
    (sum ≥ ordered → `received`, > 0 → `partially_received`).
11. `delivery.state = posted`, `posted_by`, `posted_at`. Commit.
12. Return the per-PO summary for the summary screen (§2 step 8).

Stock math stays in the MySQL triggers. PHP only inserts ledger rows (AGENTS.md rule).

### 6.4 `cancelPostedDelivery` (reversal) [Q8]

- Set `is_cancelled = 1` (+ `cancelled_at`, `cancelled_by`) on the delivery's
  `inventory__parts` rows. Triggers restore the stock.
- Decrement `quantity_received` per allocation, delete the generated
  `purchase__order_receipt(+items)`, and recompute the PO states (this can move
  `received` back to `partially_received` / `confirmed`).
- Refuse when any touched PO was closed manually (`received` via close button)
  or `cancelled`. The rule matches the existing `OrderReceiptRepository::delete()` guard.
- `delivery.state = cancelled`. The row is kept for audit.

### 6.5 Retired / changed

| Item | Fate |
|---|---|
| `acceptReceiptMetadata()` + `receipt-accept.php` | removed; nothing produces `in_progress` anymore [Q4] |
| `createReceipt()` | kept as a thin wrapper, still used by `import-procurement-history-from-gsheet.php`. Removed from UI |
| `receive-page.php/.js`, `receive-form.php`, `receipt-po-get.php`, `receipt-create.php`, route `purchase/receipts/receive` | removed. The route redirects to the new page with the PO's vendor preselected [Q5] |
| `closePoFromReceive()` + `close-po-modal.php` / `close-po.js` | kept, reused on the summary screen and the queue |
| `OrderReceiptRepository::delete()` | for rows with `delivery_id` → refuse and point to the delivery cancel action (the whole document is cancelled as a unit) |

## 7. AJAX endpoints (`public_html/components/purchases/receipts/`)

All follow the AGENTS.md endpoint shape (JSON, admin gate, POST gate, HTTP 200 +
`{success:false,error}` on validation errors, transactions in the handler).

| File | Input | Output |
|---|---|---|
| `delivery-vendors.php` (GET) | — | vendors with open-PO counts / open line counts |
| `delivery-open-items.php` (GET) | `vendor_id` | open PO lines for the vendor (PO no., part, ordered / received / remaining, price, due date). Feeds the side panel and the article search |
| `delivery-part-search.php` (GET) | `vendor_id`, `q` | vendor catalogue matches; parts that are on an open PO rank first |
| `delivery-create.php` | `vendor_id`, header | `{delivery_id}` |
| `delivery-header-save.php` | `delivery_id`, header | draft state |
| `delivery-line-save.php` | `delivery_id`, `line_id?`, `vendor_part_id`, `quantity`, `unit_price`, `sub_magazine_id?`, `comment?` | **full draft state** (lines + allocations + flags + per-PO preview) |
| `delivery-line-delete.php` | `line_id` | full draft state |
| `delivery-line-allocate.php` | `line_id`, `allocations[]` | full draft state |
| `delivery-get.php` (GET) | `delivery_id` | full draft or posted state |
| `delivery-post.php` | `delivery_id` | `{grn_number, pos:[{po_id, po_number, new_state, missing_lines[]}]}` |
| `delivery-discard.php` | `delivery_id` | — |
| `delivery-cancel.php` | `delivery_id` | — [Q8] |

Returning the full draft state from every write keeps the JS simple. It
re-renders from one payload and never patches state locally.

## 8. UI (owned by @designer at implementation time)

### 8.1 `/purchase/receipts` (list page)

- Tabs: **„Do przyjęcia”** (open POs grouped **by vendor**, one action per
  vendor: „Przyjmij dostawę”), **„Robocze”** (draft deliveries, „Kontynuuj” /
  „Usuń”), **„Historia przyjęć”** (one row per *delivery*, i.e. invoice, listing
  its POs; legacy per-PO receipts stay listed with no delivery).
- The per-PO trash/close buttons stay.

### 8.2 New page `/purchase/receipts/new` (+ `?delivery_id=N` to resume)

```
┌ Header ─────────────────────────────────────────────────────────────┐
│ Dostawca [picker]  Nr faktury/WZ [__]  Data dok. [__]  Data przyjęcia│
│ Magazyn docelowy [picker]  Waluta [PLN]  Komentarz [______________]   │
└──────────────────────────────────────────────────────────────────────┘
┌ Pozycje z faktury ─────────────────────────────┐ ┌ Otwarte zamówienia ┐
│ # | Artykuł | Ilość | Cena | Magazyn | Przydział │ │ PO/12  3/5 pozycji │
│ 1 | ABC-01  | 500   | 0,12 | domyśl. | PO/12 300 │ │  ABC-01 rem 300 →0 │
│   |         |       |      |         | PO/15 200 │ │ PO/15  1/2 pozycji │
│ 2 | XYZ-9   | 100   | 1,05⚠| …       | PO/15 100 │ │  …                 │
│ [+ wiersz: artykuł ▾ | ilość | cena | Enter = dodaj]                    │
└────────────────────────────────────────────────┘ └────────────────────┘
 Sticky bar: „4 pozycje · 3 zamówienia · 1 ostrzeżenie”   [Usuń szkic] [Zatwierdź przyjęcie]
```

- **Keyboard-first entry row:** article search → Tab → qty → Tab → price →
  Enter saves the line and puts focus back on the article field. This is the main
  speed requirement for typing an invoice.
- Price is prefilled with the price of the PO line the article would match first.
  The operator overwrites it with the invoice value.
- The right panel shows the open PO lines live: remaining before and after this
  document, so the operator sees each PO filling up.
- The allocation cell opens a small editor to override the split (manual allocation).
- After posting, the summary modal lists each touched PO with its progress, the missing lines
  and the „Zostaw otwarte” / „Zamknij zamówienie” choice.

## 9. Implementation phases (after acceptance)

| # | Phase | Output | Est. (with AI) |
|---|---|---|---|
| 1 | Schema migration + struct dump + DATABASE.md | 3 new tables, 2 FK columns, transfer-group type, legacy draft cleanup | 1–2 h |
| 2 | Entities, repositories, `DeliveryMatcher` | 6 classes + a CLI smoke script with fixed PO fixtures | 2–3 h |
| 3 | Handler methods + `postDelivery` / cancel | transactional core | 2–3 h |
| 4 | AJAX endpoints | 12 files | 1–2 h |
| 5 | UI: new delivery page + list page tabs (@designer) | page, JS, CSS | 3–5 h |
| 6 | Remove the per-PO receive path, redirect the old route, update docs (ROUTING, MODULES, CLASSES, PLAN changelog) | cleanup | 1 h |
| 7 | Manual verification in a browser on the dev DB (scenarios below) | checklist | 1 h + your review |

Total: about 1.5–2 working days of agent time plus your review.

**Verification scenarios** (manual, there is no test suite):
1. One invoice, two POs, same part: the split follows the price match and then FIFO.
2. A line that finishes one PO and partly fills another: the states are `received` + `partially_received`.
3. Surplus over all open POs follows the [Q3] policy.
4. Price mismatch: a warning shows, posting still works, and the invoice price is stored on the line.
5. Manual allocation override survives adding more lines.
6. Two browser tabs post overlapping drafts: the second one re-matches or aborts cleanly.
7. Cancelling a posted delivery: stock, `quantity_received` and PO states are restored.
8. Legacy receipts still show in history and in the detail modal.

## 10. Decisions (resolved 2026-10-31 — every default below was **accepted**)

- **Q1 — Draft persistence.** Lines saved server-side as typed (resumable) *vs.*
  kept only in the browser until „Zatwierdź”? Default: **server-side draft**.
- **Q2 — What the invoice price does.** Default: stored on the delivery line,
  price match is used to pick the PO, a mismatch is a warning only, and it is written to
  `purchase__order_item.unit_price` **only when that is NULL**. It does not touch
  `list__parts.price`.
- **Q3 — Quantity beyond all open PO lines / article on no PO.** Options: block;
  allow as over-delivery on the last matched PO line; book as unordered stock
  (surplus stays unallocated). Default: **book surplus into stock as unallocated,
  with a mandatory comment**.
- **Q4 — `in_progress` / „Potwierdź odbiór” step.** Default: **retire it**,
  migrate existing `in_progress` POs as in §4.5.
- **Q5 — Old per-PO receive page.** Default: **remove**, redirect to the new page
  with the vendor preselected.
- **Q6 — Header requirements.** Is the invoice/WZ number mandatory? Do you need
  to distinguish Faktura vs WZ? Default: number mandatory, type selectable.
- **Q7 — Units.** Assumed: invoice quantity is in the same unit as the PO line
  (no packs↔pieces conversion on the invoice). Correct?
- **Q8 — Correcting a posted delivery.** Default: whole-document cancel
  (reversal) only. No per-line edit after posting.
