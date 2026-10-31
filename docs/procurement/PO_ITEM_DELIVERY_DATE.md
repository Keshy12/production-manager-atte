# Terminy dostawy per pozycja zamówienia (PO)

**Status:** wdrożone
**Dotyczy:** `purchase__order_item.expected_delivery_date`
**Skrypt migracji:** `src/cron/migrate-po-item-expected-delivery-date.php`

---

## 1. Problem

Data dostawy istniała tylko na **nagłówku** zamówienia:

```
purchase__order.expected_delivery_date   ← „Planowana dostawa" dla całego zamówienia
```

W praktyce jeden zamówienie często ma pozycje o **różnych terminach** —
części magazynowe idą od jednego dostawcy, części na zlecenie od
producenta. Operator potwierdzający odpowiedź dostawcy
(`/admin/purchase/documents/respond?id=N&type=po`) musiałby zapisać
termin „nagłówkowy", który dla części pozycji jest po prostu zły.

## 2. Co się zmieniło

### 2.1 Baza

Nowa, nullable kolumna na pozycji zamówienia:

```sql
ALTER TABLE `purchase__order_item`
  ADD COLUMN `expected_delivery_date` date DEFAULT NULL AFTER `picked_pack_size`;
```

| Wartość | Znaczenie |
|---|---|
| `DATE` | własny termin dostawy tej pozycji |
| `NULL` | brak własnego terminu — UI pokazuje wtedy datę z nagłówka (lead time dostawcy) jako wartość domyślną pola |

Kolumna **nie jest** kaskadowa ani nie zmienia semantyki istniejących
kolumn. Nagłówek `purchase__order.expected_delivery_date` zostaje bez
zmian i nadal oznacza „planowana dostawa całego zamówienia".

### 2.2 Migracja — co trzeba zmienić w bazie

1. **Dodać kolumnę** (jedyna wymagana zmiana):
   ```bash
   php src/cron/migrate-po-item-expected-delivery-date.php
   ```
   Skrypt jest idempotentny (kolumnę sprawdza przez `information_schema`
   przed ALTERem), nie tworzy tabeli i nie rusza danych istniejących
   wierszy. Pracuje wyłącznie na bazie `atte_ms` (strażnik).

2. **Opcjonalny backfill** — kopiuje datę z nagłówka na pozycje, które
   nie mają własnej:
   ```bash
   php src/cron/migrate-po-item-expected-delivery-date.php --backfill
   ```
   Uwaga: to zapis danych. Kopiuje datę nagłówka **także** do pozycji
   już potwierdzonych i odebranych zamówień. Domyślnie wyłączony —
   bez niego baza pozostaje „niepamiętająca" per pozycję, a UI i tak
   pokazuje poprawną datę domyślną.

3. **Świeża instalacja** — kolumna jest już w blokach `CREATE TABLE`
   w `src/cron/migrate-to-procurement-schema.php` oraz
   `src/cron/migrate-production-to-purchase-schema.php` (ten drugi
   weryfikuje kształt tabeli — bez aktualizacji zgłaszałby
   „unexpected column").

### 2.3 Kod

| Plik | Zmiana |
|---|---|
| `src/classes/Utils/Purchase/Order/class-purchaseorderitem.php` | nowe pole `?string $expectedDeliveryDate` |
| `src/classes/Utils/Purchase/Order/class-purchaseorderitemrepository.php` | `expected_delivery_date` w `SELECT` + metoda `setExpectedDeliveryDate(int $id, ?string $date)` (osobna, bo `update()` świadomie nie dotyka tej kolumny — jest wołany przez inline-edytor pozycji na stronie edycji) |
| `src/classes/Utils/Purchase/Order/class-purchaseactionhandler.php` | `confirmPoWithPrices()` przyjmuje per pozycji klucz `expected_delivery_date` i zapisuje go w tej samej transakcji co ceny i przejście `sent → confirmed` |
| `public_html/components/purchases/documents/document-po-respond-ajax.php` | parsuje i waliduje `items[<id>][expected_delivery_date]` (format `RRRR-MM-DD`; pusta wartość = wyczyść termin; brak klucza = nie ruszaj zapisanego) |
| `public_html/components/Admin/Purchase/Documents/documents-respond.php` | kolumna „Dostawa" w siatce pozycji (tylko PO) + pole „Dostawa dla wszystkich" + przyciski szybkiego wyboru daty (ikoniczne) + przeniesiony panel „Dane odpowiedzi" nad listę pozycji |
| `public_html/components/Admin/Purchase/Documents/documents-respond.js` | akcent zmienionej daty, wliczanie jej do licznika „Zmieniono: N z M", rozpropagowanie daty „dla wszystkich" na wszystkie wiersze, obsługa przycisków szybkiego wyboru (`applyQuickEta`), podpowiedź względna pod datą (`relativeEtaHint`) |
| `public_html/components/Admin/Purchase/Documents/documents-respond-ui.css` | piąta kolumna siatki (`dx-lines--po`) + układ responsywny |

## 3. Zachowanie w UI

* **Domyślna wartość pola** (kolejność, pierwsza trafiona wygrywa):
  1. data zapisana w wierszu pozycji,
  2. data nagłówka zamówienia (`purchase__order.expected_delivery_date`)
     — czyli to, co operator wybrał przy wysyłce; domyślnie wyliczone
     jako *data wysyłki + lead time dostawcy*,
  3. lead time dostawcy policzony od daty wysyłki (gdy nagłówek daty
     nie ma),
  4. pole puste — operator wpisuje datę ręcznie.
* **Puste pole = brak terminu tej pozycji** (kolumna → `NULL`). Pusta data
  **nie blokuje** potwierdzenia — to „brak własnego terminu".
* **Termin w przeszłości blokuje potwierdzenie zamówienia** (tak jak
  brak ceny). Wiersz dostaje flagę **„PO TERMINIE"** obok flagi
  „brak ceny"; komunikat w pasku akcji: „Popraw termin dostawy w N
  pozycjach — data w przeszłości blokuje potwierdzenie." Kontrola jest
  dwustronna:
  * **w przeglądarce** (`documents-respond.js`) — submit jest odrzucany.
    Komunikat pojawia się jako **przemijająca plakietka** przypięta do
    góry okna (`position: fixed` w `documents-respond-ui.css`) i znika
    sama po ~2,5 s — ma tylko zwrócić uwagę. **Stała** informacja
    (ile pozycji i co poprawić) jest w pasku akcji na dole strony,
    razem z odnośnikiem **„Przejdź do wiersza"** (klik przewija do
    wiersza i stawia w nim fokus). Strona **nie przewija się sama** —
    nic nie zostaje ukryte (tak jak w koszyku). Kontrola ceny ma
    pierwszeństwo przed kontrolą daty.
  * **na serwerze** (`PurchaseActionHandler::assertPoItemsHaveFutureDeliveryDate()`)
    — data w przeszłości w **każdej** pozycji zamówienia (także
    pominiętej w formularzu) blokuje `sent → confirmed`; komunikat
    wymienia pozycje („poz. 3 (ID 8689, E-030-007-0010)"). Pusta data
    (brak terminu) jest dopuszczalna.
* **„Dostawa dla wszystkich"** w nagłówku panelu pozycji przepisuje datę
  do każdego wiersza jednym ruchem; potem operator poprawia pojedyncze
  pozycje.
* **Szybki wybór daty** — te same przyciski co w kroku 3 wizarda wysyłki
  (`documents-send.js` → `send-exp-quick`), **bez ±1 miesiąc**.
  Są pod każdym polem daty w wierszu i przy polu zbiorczym.
  Kolejność i wygląd:

  | Przycisk | Znaczenie |
  |---|---|
  | `«` | 1 tydzień wcześniej (chevron podwójny = tydzień) |
  | `‹` | 1 dzień wcześniej (chevron pojedynczy = dzień) |
  | `›` | 1 dzień później |
  | `»` | 1 tydzień później |
  | 🗓 | Dzisiaj (skok bezwzględny, na końcu) |

  Na stałe widać **tylko glif** — bez liter. Nazwa akcji pojawia się
  **dopiero przy najechaniu** (dymek CSS z atrybutu `data-label`;
  ten sam tekst jest w `aria-label`). Kolor niesie kierunek
  (minus = czerwony, plus = zielony). Pod datą stoi podpowiedź
  względna („dzisiaj", „za 3 tygodnie", „5 dni temu"), a termin
  w przeszłości dostaje bursztynową flagę **„PO TERMINIE"** — stoi
  **obok flagi „brak ceny"**, w tym samym rzędzie co numer części
  (ten sam wygląd; klasa wiersza `is-etapast`, liczona po stronie
  serwera i przełączana przez JS przy zmianie daty).
  Semantyka jak w wizardzie: `Dziś` = dzisiejsza data (bezwzględnie),
  `±1 dzień` / `±1 tydzień` = **bieżąca wartość pola** przesunięta
  o tyle (kliknięcia się kumulują). Data nie schodzi poniżej daty
  wysłki zamówienia (`data-eta-min` na formularzu) — dostawa przed
  wysyłką nie ma sensu.
* **Licznik „Zmieniono: N z M"** — pozycja z inną datą niż domyślna
  liczy się jako zmieniona (tak jak zmiana ceny).
* **RFQ jest nietknięte** — termin dostawy dotyczy tylko zamówień.
* **Nagłówek zamówienia nie jest zapisywany z tej strony** — panel
  „Dane odpowiedzi" wysyła tylko numer PO u dostawcy i datę
  potwierdzenia.

## 4. Poza zakresem

Ścieżka **odbioru towaru** (`/purchase/receipts`) nie została zmieniona.
Kolejki odbioru nadal sortują i filtrują po dacie z nagłówka
(`purchase__order.expected_delivery_date`) — ta strona ma być
przebudowana od zera. Nowa kolumna jest w pełni opcjonalna, więc
odbiór działa tak samo przed i po migracji.
