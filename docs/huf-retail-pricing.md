# HUF bruttó kisker kalkuláció

Az **Árképzés → HUF kisker árak** munkafelület az árprojekt szezonjának
mentett, végleges HUF bekerülési értékeiből számol. Az árprojekt megtekintő
és szerkesztő oldaláról is indítható. Egy kiválasztott vagy az összes aktív,
HUF pénznemű kisker árlistára futtatható. Az árlistatörzs szezonfüggetlen;
a kalkuláció szezonját az árprojekt határozza meg. Ugyanaz az árlista
több szezonban is használható, a publikált árak szezonja a tételekhez tartozik.

## Excel-export és tizedeselválasztó

A közös admin-táblázat export a numerikus oszlopokat és a modell numerikus
castjait valódi Excel-számcellaként menti, a kapcsolódó rekordok mezőit is.
A kisker kalkuláció számított cél-beszerzési ára és kalkulációs szorzója is
számként kerül a fájlba. A szöveges azonosítók, kezdő nullák, üres értékek
és nulla számértékek megmaradnak; a százalékok egysége változatlan (12 = 12%).
Nem cserélünk pontot vesszőre szövegekben vagy az XLSX belső számformátumában.

A megjelenítés az Excel beállítását követi. Windows régiós beállításához az
Excel Fájl → Beállítások → Speciális → Rendszerelválasztók használata opciója
legyen bekapcsolva. Magyar tizedeselválasztóval például 8,8 jelenik meg,
pontot használó környezetben 8.8, ugyanabból a számcellából. A szerver nem
olvassa ki a felhasználó Windows-beállítását, és nem is szükséges ezt átadni.
A korábban letöltött, szöveges cellákat tartalmazó fájlokat újra kell exportálni.

Ellenőrzés: a mentett XLSX visszaolvasása, numerikus cellatípusok, összegezhetőség,
negatív érték, nulla/üres érték és kódszöveg megőrzése. Összesen 49 kapcsolódó
teszt, 391 assertion sikeres. Windows Excelben a tényleges megjelenítés helyi
ellenőrzést igényel; a tesztek az XLSX-ben tárolt típusokat és értékeket vizsgálják.

Ehhez nincs új migráció, Composer install, npm build, kézi feltöltés vagy
szerverkonfiguráció-módosítás. NAS-frissítés csak külön kérésre történik.

Git/NAS a `feature/pricing-module` worktree-ben:

```powershell
git add -- app/Exports/AdminTableExport.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php tests/Feature/Admin/AdminTableExportTest.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md
git commit -m "Export table decimals as native Excel numbers"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Előkészítés

1. Legyenek elmentett bekerülési értékek az árprojektben.
2. Az Árlisták törzsben legyen aktív, `Kisker` típusú, `HUF` pénznemű árlista.
3. Az Árképzési paraméterek között ugyanahhoz az árprojekthez legyen aktív
   **Kisker szorzó**, például `4.7`. A globális érték főcsoport vagy modell
   szinten felülírható; a meglévő beszállítói ország szűkítés is támogatott.
   Árlista és pénznem megadása opcionális: a szűkített érték az adott
   árlistára / pénznemre érvényes. Más árlista paraméterét nem alkalmazzuk.
4. A Kerekítési szabályok között legyen aktív **Kisker** szabály. A szokásos
   HUF beállítás: egység `1000`, mód **Legközelebbi**, korrekció `-10`.
   Árlistánként külön szabály adható meg; ez megelőzi a csak pénznemre vagy
   globálisan beállított szabályt. Azonos szinten két aktív szabály hibát ad.

Példa: `460 × 4.7 = 2162 → 2000 − 10 = 1990 HUF`.
A szorzó a bruttó kisker árat képezi; külön áfa-szorzót ebben a lépésben nem alkalmazunk.

## Munkafolyamat

- A gridben látható a bekerülési érték, az alkalmazott szorzó, a kerekítés
  előtti ár, a kalkulált, a manuális és a végleges ár, valamint a státusz.
- A Manuális HUF mező közvetlenül szerkeszthető. Az üres érték visszaállítja
  a kalkulált árat. A manuális módosítás új jóváhagyást igényel.
- A **Kisker szorzó** minden sornál a végleges, kerekítés utáni ár és a
  mentett bekerülési érték hányadosát mutatja, legfeljebb hat tizedesjeggyel.
  Például `1990 / 460 = 4,326087`, manuális árnál `2490 / 460 = 5,413043`.
  Törölt manuális ár / elfogadott új kalkuláció után a kerekített kalkulált árból számol.
  A **Kalkulációs szorzó** külön, bekapcsolható oszlop; a részletek és a tooltip
  is megőrzik az eredeti értéket. Az árképzési paraméter és snapshot nem változik.
- A manuális mező, a kijelzett szorzó és a végleges ár egységes piros lefelé /
  zöld felfelé jelzést kap: az összehasonlítás a kalkulált, **kerekített** árhoz
  történik. Azonos árnál nincs eltérésjelzés. A megtartott jóváhagyott ár szorzója
  újraszámítás után az új bekerülési értékből következik, de az ár nem íródik felül.
- A kijelzett szorzó szerint lehet szűrni, rendezni, és ez az érték kerül Excelbe is.
- A táblázat ikon-, beviteli és akciócellái is kompaktak: a sormagasság a
  szöveghez/beviteli mezőhöz igazodik. A közös stílus a bekerülési táblázatot is érinti.
  A Kisker szorzó oszlop korábbi azonosítója megmarad, így a mentett láthatóság,
  sorrend és oszlopszélesség nem vész el.
- A végleges ár piros lefelé / zöld felfelé nyila jelzi az eltérést a kalkulálttól.
- Újraszámításkor a manuális és már átnézett / jóváhagyott végleges árak megmaradnak.
  Ha releváns bemenet vagy a kalkulált eredmény változik, a **Felülvizsgálandó**
  jelzés bekapcsol, akkor is, ha a kerekített ár változatlan.
- Az **Ár megtartása és jóváhagyása** a korábbi végleges árat hagyja meg.
  A **Kalkulált ár elfogadása** a legutóbbi számított árat teszi véglegessé.
- A Jóváhagyás műveletben átnézett, jóváhagyott, 2. és 3. körös jóváhagyott
  státusz választható. Manuális vagy megtartott ár első jóváhagyásakor a
  **Módosított jóváhagyott** státuszt automatikusan kapja a sor.
- A Részletek táblázatosan mutatja a mentett bemeneteket és a kerekítési
  lépéseket. A snapshot a forrás bekerülési kalkulációt és a legutóbbi
  jóváhagyás adatait is megőrzi.
- A hiányzó szorzó, kerekítési szabály vagy érvénytelen eredmény a
  Kalkulációs hibák között a futás azonosítója alapján visszakereshető.

Az eredmények a `pricing_calculation_rows` táblába kerülnek. A meglévő,
partnerportálon használt `price_list_items` árait nem publikáljuk automatikusan.
A későbbi árlista-publikálás külön lépés lesz.

A grid minden oszlopa szűrhető és rendezhető, Excelbe exportálható;
az oszlopválasztás, sorrend és szélesség felhasználónként megmarad.
A bekerülési és kisker munkafelület beállításai külön tárolódnak.

## Jogosultságok

Az admin **Jogosultságkezelés → Szerepkörök → szerepkör szerkesztése** oldalán
mindkét munkafelület külön engedélyezhető az **Oldalak / Pages** fülön:

- Bekerülési értékek: `View:PricingCostCalculations`.
- HUF kisker árak: `View:PricingHufRetailCalculations`.

A kiválasztott oldaljog mellett az **Erőforrások / Resources → Árkalkulációs
sorok / PricingCalculationRow → View any** (`ViewAny:PricingCalculationRow`)
is szükséges. Közvetlen URL-en is ugyanígy ellenőrizzük a hozzáférést.
Korábban a kisker oldalhoz elég volt a View any: ezt most az oldaljoggal is
ki kell egészíteni a megfelelő szerepkörön. Nem adunk automatikusan új jogot.

| Művelet | Szükséges erőforrásjogok az oldal megtekintési jogain felül |
| --- | --- |
| Lista, szűrés, rendezés, Excel-export | Árkalkulációs sorok: View any |
| Részletek | Árkalkulációs sorok: View |
| Kisker manuális ár és jóváhagyás | Árkalkulációs sorok: Update |
| Bekerülési vagy kisker teljes újraszámítás | Árprojektek: Update; Árkalkulációs sorok: Create + Update |
| Szállítás% / vám% átírása és újraszámítás | Árprojektek: Update; Árkalkulációs sorok: Create + Update; Árparaméterek / PricingParameter: Create + Update |

A paraméterválasztás új, szűkebb szabályt is létrehozhat, ezért itt Create és
Update együtt szükséges. A szerkesztőmezők és akciók a jogosultságokhoz
igazodnak. A szolgáltatások is ellenőrzik a jogokat minden adatírás előtt;
a megerősítés újra ellenőriz, nem csak a szerkesztés megkezdése.
A csak megtekintő szerepkör nem módosíthat paramétert, árat vagy jóváhagyást,
és nem indíthat újraszámítást, általános sor-szerkesztést vagy tömeges törlést.

Nincs új migráció vagy automatikus szerepkör-módosítás. Shield-generálás sem
kell: a két oldal az Oldalak fülön megjelenik; a Shield a kijelölt permission
rekordokat a szerepkör mentésekor létrehozza, ha még hiányoznak. A super_admin
hozzáférése megmarad. A partnerportál jogosultságait nem módosítjuk.

## Tesztelés és telepítés

### Felülvizsgálandó oszlop színei

A HUF kisker táblázatban a Felülvizsgálandó oszlop igaz értéke piros (`danger`),
a hamis értéke zöld (`success`). Az ikonok, az árak és a felülvizsgálat logikája
változatlan. Ehhez nincs új migráció, Composer install, frontend build vagy
kézi fájlfeltöltés.

A színjavítás külön kért kihelyezéséhez, a pricing worktree-ből:

```powershell
git add -- app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md
git commit -m "Show required retail review in red and no review in green"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

### Oszloponkénti tizedesbeállítás

A Bekerülési értékek és HUF kisker árak munkafelület felső **Tizedesjegyek**
gombja egy beállító ablakot nyit. Oszloponként választható az Alapértelmezett,
vagy 0–6 tizedesjegy. A Mentés bezárja az ablakot és frissíti a kijelzést.
Alapértelmezett választással az eredeti megjelenítés áll vissza: a kisker áraknál
a felesleges záró nullák elmaradnak, a bekerülési táblázat korábbi pontossága megmarad.
A konkrét szám kiválasztása pontosan annyi tizedest jelenít meg, záró nullákkal is.

- Bekerülési oldalon: beszerzési ár, árfolyam, kalkulált, manuális és végleges ár.
- Kisker oldalon: eredeti beszerzési ár, bekerülési érték, kisker szorzó és az
  oszlop fölötti átlag, kalkulációs szorzó, kerekítés előtti, kalkulált és végleges
  kisker ár, cél-beszerzési ár.
- A szerkeszthető kisker manuális ármező, szállítás% és vám% beviteli mezők teljes
  pontosságúak maradnak: egy megjelenítési kerekítés nem írhat vissza más értéket.
  A részletező modal és tooltip eredeti részletes számai nem változnak.

A beállítás a meglévő `admin_ui_preferences` táblában, felhasználónként és
munkafelületenként külön tárolódik (`*_decimal_places`). Újbóli belépéskor is
megmarad, más felhasználó vagy a másik munkafelület beállítását nem befolyásolja.
Nem módosítja az oszlopszélességet, láthatóságot, sorrendet vagy a szűrőket.
A csak megtekintő szerepkör is állíthatja saját megjelenítését; az oldalhoz
szükséges jogosultságot mentéskor is ellenőrizzük.

Az Excel-export ugyanilyen megjelenítési pontosságot kap a kiválasztott
oszlopokon. A számok továbbra is teljes pontosságú numerikus cellák: csak az
invariáns Excel-számformátum (`#,##0`, `#,##0.00` stb.) változik, nem a cella
értéke. A Windows/Excel tizedeselválasztója és a szöveges kódok megmaradnak.
Az Alapértelmezett opciónál a korábbi exportformátum marad.
Számítás, jóváhagyás, eltérésjelzés, keresés és rendezés a tényleges, nem a
kijelzéshez kerekített értékek alapján működik.

Ellenőrzés: 75 kapcsolódó teszt, 647 assertion sikeres. A mentett XLSX-et is
visszaolvastuk, numerikus cellatípusokkal, pontos értékekkel és kiválasztott
számformátumokkal. A sok Livewire-modal teszt futtatásához a CLI-parancsban
ideiglenes `-d memory_limit=256M` kapcsolót használunk; nincs php.ini/.env vagy
szerver-memóriabeállítás módosítás.

Nincs új migráció, Composer install, frontend build, kézi feltöltés vagy
szerepkör-módosítás. A jelenlegi, még helyi felülvizsgálati színjavítással és tömeges jóváhagyással együtt
kihelyezhető a pricing worktree-ből:

```powershell
git add -- app/Exports/AdminTableExport.php app/Filament/Pages/PricingCostCalculations.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php app/Filament/Support/AdminResourceTable.php app/Filament/Support/Concerns/HasPricingTablePreferences.php app/Services/Pricing/RetailPriceReviewService.php resources/views/filament/pages/pricing-cost-calculations.blade.php tests/Feature/PricingDecimalPreferencesTest.php tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/Admin/AdminTableExportTest.php tests/Feature/HufRetailCalculationTest.php tests/Frontend/column-manager-close.test.mjs docs/huf-retail-pricing.md
git commit -m "Add bulk retail approval, decimal preferences and column manager closing"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

### Oszlopkezelő bezárása az alkalmazás után

A jelenlegi oszlopválasztó/rendező legördülő panel (`ColumnManagerLayout::Dropdown`),
nem normál Filament-akció modal. A korábbi 100 ms-os, bármely látható modal-bezáró
gombra kattintó kód ezért nem tudta bezárni, és nem célzottan kezelte a felületet.
Ezt eltávolítottuk. A közös `AdminResourceTable` konfiguráció Apply gombja most
megvárja a Filament oszlopkezelő mentési műveletét, és csak hibamentes eredmény
után zárja be a saját legördülő paneljét. Hiba esetén nyitva marad, a beállítás
újrapróbálható. A meglévő oszlopszélesség/morph/resize figyelés megmarad.
Ez az összes, közös konfigurációval készült admin táblázatra érvényes.

Ellenőrzés: 4 admin-menü/oszlopkezelő teszt, 14 assertion sikeres. A JavaScript
regressziós teszt a telepített Filament oszlopkezelő és dropdown implementációját
használja, mockolt szervermentéssel. Igazolja a mentés megvárását, a hibánál
nyitva maradást és a sikeres újrapróbálás utáni bezárást.
Node.js elérhető fejlesztői környezetben: `node tests/Frontend/column-manager-close.test.mjs`.
A teszt a beépített JavaScript-futtatókörnyezetben is futtatható; a NAS-ra nincs
szükség Node.js-re, nincs új frontend asset vagy build. Az alkalmazás vendor
fájljait nem módosítottuk.

### HUF kisker árak tömeges átnézése/jóváhagyása

A sor elején található jelölőnégyzettel több ár választható ki. A fejlécben
az aktuális oldal összes sora kijelölhető; a Filament összes kijelölése funkciója
az aktuális szűrés összes oldalára is kiterjeszti a kijelölést. Egyes sorok
visszavonhatók, vagy az egész kijelölés törölhető. Szűrőváltáskor a kijelölés törlődik.
A lezárt/inaktív árprojekt sorai nem jelölhetők ki.

A **Kijelölt árak jóváhagyása** gomb megerősítő ablakot nyit, amelyben a szint:
Átnézett ár / Jóváhagyott ár / 2. körös / 3. körös jóváhagyott.
Minden kijelölt sor **aktuális végleges ára** marad meg; a manuális vagy megtartott
árat nem cseréljük a kalkuláltra. Az alap Jóváhagyott szint manuális/megtartott árnál
Módosított jóváhagyott státuszt eredményez. A jóváhagyó és a bemeneti snapshot
soronként mentődik, az élő árlisták nem változnak.

Ugyanaz az `Update:PricingCalculationRow` jogosultság kell, mint az egyedi
művelethez, a munkafelület saját megtekintési joga és a sorok ViewAny joga mellett.
Csak megtekintőnek a tömeges gomb és a kijelölés nem látszik. Az akció az oldalhoz
való hozzáférést, a szolgáltatás minden sor módosítási jogát mentéskor ismét ellenőrzi.
Hamisított bekerülési/EUR sorazonosító nem kerülhet a HUF kisker akcióba.

Elavult kalkuláció, hiányzó sor/projekt, lezárt/inaktív projekt vagy időközben
megvont sorjogosultság esetén a sort kihagyjuk. Az összesítés megmutatja a sikeres
és kihagyott sorok számát, valamint a kihagyás okait. Elavult kalkulációt előbb
újra kell számítani. Sikeres futás után a kijelölés törlődik.
Váratlan rendszer/adatbázishibánál a teljes művelet tranzakciója visszagördül;
nem marad félbehagyott tömeges jóváhagyás.

Ellenőrzés: 11 tömeges jóváhagyási teszt, 97 assertion sikeres, beleértve a több
lapos szűrt kijelölést, kihagyást, jogosultság-visszavonást és tranzakciós visszaállást.
Az összes jelenlegi változtatás teljes tesztfutása: 130 teszt, 891 assertion;
128 sikeres, a két korábbról ismert admin-resource darabszám-elvárás hibázik
(30 helyett 32, illetve 31). Új teszthiba nincs. A Pint és a JavaScript
oszlopkezelő-regressziós teszt is sikeres.
Nincs új migráció, Composer install, npm build, kézi feltöltés vagy Shield-generálás.
Kihelyezéskor csak cache-törlés és újraépítés szükséges; a fenti Git/NAS és
cPanel parancsok az összes jelenlegi helyi változtatást együtt tartalmazzák.

### Értékoszlopok jobbra igazítása

A Bekerülési értékek, a HUF kisker árak és az általános árkalkulációs lista
számoszlopai jobbra igazítottak: árak, árfolyam, százalékok, szorzók és
vizsgált árak darabszáma. A fejléc és a szerkeszthető beviteli mező is követi
az igazítást. A modell-/beszállítókód, pénznem és más szöveges mezők változatlanok.
Az igazítást a Filament natív oszlopkonfigurációja kezeli; megmarad a mentett
oszlopszélesség, sorrend, tizedesbeállítás és eltérésjelzés. Számítás, adat és
Excel-export nem változik. A regressziós teszt a ténylegesen renderelt fejléceket
és beviteli mezőket is ellenőrzi.

Ellenőrzés: 87 kapcsolódó teszt, 813 assertion sikeres; Pint és `git diff --check`
hibamentes. A változás egyelőre csak helyi, külön NAS-kihelyezési kérésre vár.

Ehhez nincs új migráció, Composer install, npm build, kézi feltöltés,
új jogosultság vagy .env módosítás. Kihelyezés után cache-frissítés szükséges.

Git/NAS a `feature/pricing-module` worktree-ben, külön kihelyezési kérésre:

```powershell
git add -- app/Filament/Support/AdminResourceTable.php tests/Feature/PricingDecimalPreferencesTest.php docs/huf-retail-pricing.md
git commit -m "Right align pricing values, headers and editable inputs"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

### Árlista-műveletek adatforgalma és teljesítménye

Az első optimalizálás nem változtat a lapméreten, az árakon, a szűréseken,
az oszlopokon vagy a jóváhagyási szabályokon. A manuális ár és a rendezés a
teljes listát frissíti, hogy a sorrend, az átlag, a státusz és a színezés helyes maradjon.

A 100 soros, helyi SQLite/Livewire tesztben a válasz méretének döntő része
a teljes táblázat HTML-je, nem a Livewire-állapot. Szintetikus adatokkal mértünk,
nem a NAS üzleti adatain módosítottunk:

| Művelet | Válasz tömörítés nélkül | Gzip 5 becslés |
| --- | ---: | ---: |
| Manuális kiskerár módosítása | 3 347 718 bájt | kb. 87 700 bájt |
| Ár szerinti rendezés | 3 347 877 bájt | kb. 86 700 bájt |
| Jóváhagyó ablak megnyitása | 22 898 bájt | kb. 4 100 bájt |
| Oszlopszélesség mentése, korábban | 3 343 916 bájt | kb. 85 800 bájt |
| Oszlopszélesség mentése, javítás után | 6 295 bájt | kb. 1 230 bájt |

A tömörített méretek az alkalmazás válaszának helyi `gzencode(..., 5)` méréséből
származnak; nem élő Nginx-válaszok. A NAS nyilvános login GET ellenőrzésekor,
`Accept-Encoding: gzip` kérés mellett nem érkezett `Content-Encoding` fejléc.
Az élő, hitelesített árlista POST-ot külön a böngésző Network nézetében kell
ellenőrizni kihelyezés után. A pontos méret az oszlopoktól, soroktól és adatoktól függ.

Az oszlopszélesség mentése és visszaállítása most csak a felhasználói
beállítást menti. A böngésző már elvégezte a méretezést, ezért nem küldjük újra
a táblázatot. A Filament natív részleges-render hookját használjuk, nem globális
`skipRender()`-t: az ugyanabba a kérésbe csomagolt ármódosítás vagy oszlopválasztás
továbbra is teljes, helyes frissítést kap. A rejtett/újrarendezett oszlopok és
a következő belépéskor visszatöltött szélességek változatlanul működnek.
Az önálló szélességmentés helyi mért ideje kb. 0,87 s-ról 0,04 s-ra csökkent;
ez nem NAS-teljesítménygarancia.

Ellenőrzés: a teljes csomag 136 tesztje / 1003 assertionje közül 134 teszt sikeres;
csak a két korábbról ismert admin-resource darabszám-elvárás hibázik (32/31 helyett 30).
Új hiba nincs. A 100 soros payload-teszt, a mentett szélességek és a csomagolt
kérések tesztjei sikeresek; gzip-dekódolás után a válasz bájtra azonos.
Pint, `git diff --check`, a telepítő PowerShell és beágyazott PHP szintaxisa hibamentes.
Az Nginx runtime `nginx -t` és az élő gzip POST ellenőrzése még üzemeltetői lépés.

Az Nginx-konfiguráció előkészíti a gzip tömörítést a HTML/JSON és szöveges
assetek számára. Ez a rendezés és ármódosítás hálózati forgalmát is jelentősen
csökkenti, de nem szünteti meg a szerveroldali HTML-renderelés költségét.
A képek és XLSX-exportok bináris típusa nincs a gzip típuslistában.
A `Vary: Accept-Encoding` fejléc és a proxy mögötti privát/no-cache válaszok kezelése
az [Nginx hivatalos gzip-beállításait](https://nginx.org/en/docs/http/ngx_http_gzip_module.html)
követi. HTTPS-élesítésnél a szolgáltatóval a dokumentációban említett BREACH-kockázatot
is ellenőrizni kell; a cPanel konfigurációját nem módosítjuk automatikusan.

Fontos: a projekt Git-frissítése **nem feltétlenül frissíti** a NAS Nginx-be
csatolt konfigurációs fájlt. A runtime mount azonosítása, a beállítások átvitele,
`nginx -t`, reload és a tömörítés élő ellenőrzése külön szükséges; részletek a
`docs/nas-deployment.md` Teljesítmény és HTTP-tömörítés részében. A jelenlegi
`preorder-admin artisan` jogosultság önmagában erre nem elég. A közvetlen,
csak olvasásos Docker-inspect próbára a NAS jelszót kért, ezért a mountot és
a konfiguráció szintaxisát élőben még nem ellenőriztük.

Nincs új migráció, Composer install, npm build, ár-újraszámítás, Shield-generálás
vagy .env módosítás. Az alkalmazáshoz cache-frissítés kell. Kézi beavatkozás:
a NAS host-mounted Nginx-konfiguráció frissítése és újratöltése; az app-only
univerzális telepítő külön `NGINX ACTION REQUIRED` jelzést ad, ha ez a fájl változott.

Git/NAS a `feature/pricing-module` worktree-ben, külön kért kihelyezéshez:

```powershell
git add -- app/Filament/Support/Concerns/HasPricingTablePreferences.php infrastructure/docker/nginx/default.conf infrastructure/scripts/deploy-nas.ps1 tests/Feature/PricingDecimalPreferencesTest.php tests/Feature/HufRetailCalculationTest.php tests/Feature/PricingPayloadPerformanceTest.php docs/huf-retail-pricing.md docs/nas-deployment.md
git commit -m "Avoid layout rerenders and prepare compressed Livewire responses"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

A cPanel webszerver tömörítését külön a tárhelyszolgáltatóval kell egyeztetni.
A NAS Nginx-konfigurációját nem másoljuk át az éles tárhelyre.

### Kalkulációs modul telepítése

A helyi vendor könyvtár közös junctionja mellett is a helyes worktree-t teszteli:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/HufRetailCalculationTest.php tests/Feature/DatabaseMigrationTest.php
```

Új migráció szükséges: `2026_10_12_130000_add_retail_inputs_to_pricing_calculation_rows`.
Composer install, npm build és kézi assetfeltöltés nem szükséges.
Az árprojekt szorzóit és kerekítési szabályait a felületen kell beállítani,
meglévő paramétert nem írunk felül alapértelmezett adatokkal.

Commit/push a `feature/pricing-module` worktree-ben, csak a módosítás fájljaival:

```powershell
git add -- app/Filament/Pages/PricingCostCalculations.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Resources/PricingCalculationRows app/Filament/Resources/PricingProjects/Pages app/Filament/Support/Actions/CalculateHufRetailAction.php app/Filament/Support/Concerns/HasPricingTablePreferences.php app/Models/PricingCalculationRow.php app/Services/Pricing database/migrations/2026_10_12_130000_add_retail_inputs_to_pricing_calculation_rows.php resources/views/filament/pages/pricing-cost-calculations.blade.php resources/views/filament/pricing/retail-calculation-details.blade.php tests/Feature/HufRetailCalculationTest.php tests/Feature/DatabaseMigrationTest.php infrastructure/scripts/test-worktree.php docs/huf-retail-pricing.md
git commit -m "Add HUF retail price calculation and review workspace"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

A NAS-frissítő script elvégzi a migrációt, majd a gyorsítótár újraépítését.
Ez a mellékág fejlesztői funkciója; jelenleg nincs cPanel-telepítés.
Későbbi, külön jóváhagyott élesítéskor az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Beszállítói oszlopok, alku-célár és átlagos szorzó

- Az oszlopválasztóban elérhető **Beszállító kód** (`erp_partner_code`),
  **Beszállító neve**, **Eredeti besz. ár** és **Besz. pénznem**.
  Ezek az adott kalkulációhoz kiválasztott beszerzési ár beszállítóját/pénznemét mutatják.
  Rendezhetők, szűrhetők és Excelbe exportálhatók.
- A **Cél besz. ár** a beszerzési ár pénznemében jelenik meg.
  Manuális vagy tudatosan megtartott árnál az arányos költségmodell szerinti képlet:

  `cél besz. ár = eredeti besz. ár × végleges kisker ár / (bekerülési HUF × kalkulációs szorzó)`

  Az eredetit meghaladó célt az eredetire korlátozzuk. Csökkentés esetén piros a cella.
  Négy tizedesre lefelé kerekítünk: ez a beszerzési ár tárolható pontossága,
  és így a célár nem csökkenti az elérhető szorzót a beállított érték alá.
  Manuális/megtartott ár nélkül az eredeti beszerzési ár marad, még akkor is,
  ha a kisker kerekítés lefelé térítette el az effektív szorzót.
  Hiányzó adatnál `-` jelenik meg; nem írunk semmit a beszerzési ártörzsbe.
- A **Kisker szorzó** fejlécében **Átlag** látszik: a szűrt sorok
  `végleges kisker ár / bekerülési érték` szorzóinak **számtani átlaga**.
  Nem `összes ár / összes bekerülési érték`, nem mennyiséggel súlyozott átlag,
  és nem csak a megjelenített lap átlaga. A pozitív bekerülési érték nélküli sorok kimaradnak.
  Keresés, egyedi oszlopszűrés, árprojekt/árlista szűrés és manuális ármódosítás után frissül.
  Minden szűrt modell/szín/árlista kalkulációs sor egyenlő súllyal szerepel benne.
- A kalkulációs szorzó továbbra is megmarad külön, bekapcsolható oszlopként.
  A fő szorzó ezért a kerekítés hatását manuális ár nélkül is megmutatja.
  A meglévő oszlopazonosító és mentett szélesség/sorrend megmarad.

Ehhez és a felülvizsgálati javításhoz nincs új migráció, Composer install,
frontend build, kézi assetfeltöltés vagy .env/jogosultság-módosítás.
A korábbi hibás jóváhagyások ellenőrzéséhez telepítés után az érintett projektben
egyszer HUF kisker-újraszámítás szükséges, az árak megőrzésével.

A két változtatás együttes Git/NAS-telepítése a pricing worktree-ben:

```powershell
git add -- app/Models/PricingCalculationRow.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php app/Filament/Support/AdminResourceTable.php app/Services/Pricing/RetailCalculationDependencyService.php app/Services/Pricing/CostCalculationService.php app/Services/Pricing/HufRetailCalculationService.php app/Services/Pricing/HufRetailCalculator.php app/Services/Pricing/RetailPriceReviewService.php resources/views/filament/pages/pricing-cost-calculations.blade.php resources/views/filament/pricing/retail-calculation-details.blade.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md
git commit -m "Review changed retail inputs and add procurement pricing insights"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Külön jóváhagyott cPanel-élesítéskor, éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Bekerülési változások és jóváhagyások felülvizsgálata

- A bekerülési sor releváns adatainak mentett változása automatikusan jelöli a
  hozzá tartozó meglévő HUF kisker sorokat. Az árprojekt, termék és szín alapján
  minden érintett árlistára érvényes; más terméket/színt/projektet nem érint.
- Figyelt adatok: végleges bekerülési érték, beszerzési ár, árfolyam, szállítási
  és vám százalék, kiválasztott beszerzési ár/beszállító/pénznem és a forrás azonosítói.
  Nem elég az updated_at vagy a snapshot időbélyegének változása; változatlan
  újraszámítás, átnevezés és önmagában a jelöltek számának változása nem jelöl.
- A jelölés nem változtatja meg a manuális/végleges árat, a státuszt vagy a
  korábbi jóváhagyás snapshotját. Nem indít automatikusan teljes kisker-újraszámítást.
  A bekerülési mentés és az automatikus jelölés a kalkulációs tranzakció része.
- A következő HUF kisker-újraszámítás a bekerülési és kisker bemeneteket,
  szorzót és a kerekítési szabályt is összehasonlítja, nem csak a kerekített árat.
  A legutóbbi jóváhagyás bemenetei is megmaradnak összehasonlítási alapnak.
  A manuális/jóváhagyott ár mindaddig megmarad, amíg a felhasználó dönt.
- Elavult kalkulációt nem lehet jóváhagyni, elfogadni vagy manuális átírással
  érvényessé tenni. A felület figyelmeztet, hogy előbb HUF kisker-újraszámítás szükséges.
  A jelzés csak az aktuális kalkuláció átnézése/jóváhagyása vagy tudatos ármódosítása
  után kapcsol ki. Még nem átnézett, nem felülírt kalkulált sornál a friss újraszámítás
  önmagában megszünteti a jelzést.
- Kisker szorzó/kerekítési szabály szerkesztése után továbbra is futtatni kell
  a HUF újraszámítást; az új feltétel változatlan kerekített eredménynél is jelez.

A korábbi hibával érintett soroknál a kihelyezés után **egyszer futtasd a HUF
kisker árak újraszámítását** az érintett árprojektben. Az eredmények a korábbi
jóváhagyási bemenetekkel is összevetődnek, az árakat nem írjuk felül automatikusan.
Régi jóváhagyásnál csak a korábban elmentett bemenetek állíthatók helyre; új
jóváhagyásnál a másolt bekerülési bemeneteket is külön megőrizzük a snapshotban.

Ehhez a javításhoz **nem kell új migráció, Composer install, frontend build,
kézi feltöltés, .env módosítás vagy Shield-generálás**.

Git/NAS a `feature/pricing-module` worktree-ben:

```powershell
git add -- app/Models/PricingCalculationRow.php app/Services/Pricing/RetailCalculationDependencyService.php app/Services/Pricing/CostCalculationService.php app/Services/Pricing/HufRetailCalculationService.php app/Services/Pricing/HufRetailCalculator.php app/Services/Pricing/RetailPriceReviewService.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php resources/views/filament/pricing/retail-calculation-details.blade.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md
git commit -m "Flag retail prices for review when calculation inputs change"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Kompakt sorok és manuális szorzó frissítésének telepítése

Ehhez a megjelenítési frissítéshez **nem kell migráció**, Composer install,
frontend build, kézi feltöltés, új jogosultság vagy .env módosítás.
A megjelenített szorzó számított adat, nem új adatbázismező.

Ellenőrzés: 29 kapcsolódó teszt, 224 assertion sikeres. A teljes csomagból
68/70 teszt sikeres; a két korábbról ismert admin-resource darabszám-elvárás hibázik.
A valódi Filament táblázat helyi böngészős megjelenítésében a sorok kb. 57 px-ről
29 px-re csökkentek, a beviteli mező 24 px magas. A világos/sötét témát és a
piros/zöld nyilakat ellenőriztük.

A `feature/pricing-module` worktree-ben:

```powershell
git add -- app/Models/PricingCalculationRow.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php app/Filament/Support/AdminResourceTable.php resources/views/filament/pages/pricing-cost-calculations.blade.php resources/views/filament/pricing/retail-calculation-details.blade.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md
git commit -m "Compact retail rows and show manual price multiplier"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanelen csak későbbi, külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
