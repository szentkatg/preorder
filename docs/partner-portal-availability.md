# Partnerportál elérhetőségének kapcsolása

Az adminban a **Rendszerbeállítások → Partnerportál elérhetősége** oldalon
állítható a **Partnerportál elérhető** kapcsoló és a **Partneroldali üzenet**.
A módosítás csak a **Beállítások mentése** gomb és a megerősítés után érvényes.
Lezáráskor kötelező nem üres, legfeljebb 4000 karakteres üzenetet megadni.

Az üzenet egyszerű szöveg: a sortöréseket megőrizzük, a HTML-t nem futtatjuk.
Minden partner a megadott szöveget látja, automatikus fordítás nincs; a lap címe
és az Újrapróbálás gomb magyar/angol fordítással jelenik meg. Igény szerint az
admin két nyelven is beleírhatja az üzenetet ugyanabba a mezőbe.

## Hatókör

- A `/partner/*` felület egésze lezáródik: a bejelentkezés, jelszókezelés,
  szezonválasztás, rendelések, exportok és a megosztott rendelőlapok is.
- A már megnyitott partneroldalak következő szerverkérését, köztük a Livewire
  mentéseket és a bejelentkezést is tiltja. A kliensben már megjelenített oldalt
  nem tünteti el magától, és a korábban elindult szerverkéréseket nem szakítja meg.
- A letiltott kérés 503-as választ és a megadott üzenetet kapja. A Livewire
  hibamegjelenítésében is ez a válasz jelenik meg; frissítéskor a teljes lezáróoldal
  látszik. A válasz nem gyorsítótárazható, újrapróbálási idő 60 másodperc.
- Az admin, az alkalmazás állapotellenőrzése (`/up`) és a partner kijelentkezése
  továbbra is működik. Adminnak sincs kivétele a partneroldalak lezárása alól.
- Nem töröl rendelést vagy más üzleti adatot, és nem lépteti ki a partnereket.
  Visszakapcsolás után a következő kéréskor/frissítéskor ismét használható a felület.

Ez **nem** Laravel `artisan down` mód: az admin nyitva marad. Adatbázist vagy
alkalmazást érintő telepítésnél továbbra is a telepítési/mentési eljárást kell
használni, és meg kell várni a folyamatban lévő műveletek befejezését.

## Jogosultság és tárolás

- `View:PartnerPortalAvailability`: az adminoldal megtekintése.
- `Update:PartnerPortalAvailability`: a kapcsoló és az üzenet mentése.

A super_admin a meglévő szabály szerint hozzáfér. Más szerepkörnél külön kell
hozzárendelni a jogokat; megtekintési joggal a beállítások csak olvashatók.
Az engedélyeket a szerver minden módosításkor ellenőrzi, a visszavont megtekintési
jog pedig a következő Livewire-kéréskor érvényesül.

A `partner_portal_settings` tábla 1-es rekordja tárolja a közös állapotot,
üzenetet, verziószámot, az utolsó módosító admin azonosítóját és az időpontot.
Nincs kérésközi gyorsítótár. Párhuzamos adminoldalaknál a régi verzió nem írhatja
felül az újabb beállítást: ilyenkor újra kell tölteni az oldalt.

A `2026_11_17_120000_create_partner_portal_availability` migráció **bekapcsolt**
alapállapottal hozza létre a beállítást és a két jogosultságot. Meglévő partner-,
rendelési és jogosultság-hozzárendelési adatokat nem módosít. A kód telepítésekor
a migrációt a szokásos telepítési karbantartásban kell futtatni, mielőtt a
partneroldalakat ismét elérhetővé tesszük.

## Ellenőrzés

`tests/Feature/Partner/PartnerPortalAvailabilityTest.php`: kezdeti állapot,
admin mentés/megerősítés/visszavonás, visszanyitás, magyar/angol megjelenítés,
HTML-escaping, gyorsítótártiltás, partner és megosztott rendelőlap valódi HTTP és
Livewire-kérései, bejelentkezés tiltása, admin elérhetőség, jogosultságok,
üzenetvalidálás és párhuzamos módosítások kezelése.
