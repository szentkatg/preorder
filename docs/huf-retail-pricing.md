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

A munkafelülethez az árkalkulációs sorok `ViewAny` joga szükséges;
a Részletekhez `View`. A manuális ármódosítás és jóváhagyás `Update` jogot
igényel. Teljes újraszámításhoz az árprojekt `Update`, az árkalkulációs sorok
`Create` és `Update` joga is kell. Új Shield-generálás ehhez nem szükséges.

## Tesztelés és telepítés

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
