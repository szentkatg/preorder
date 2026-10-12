# HUF bruttó kisker kalkuláció

Az **Árképzés → HUF kisker árak** munkafelület az árprojekt szezonjának
mentett, végleges HUF bekerülési értékeiből számol. Az árprojekt megtekintő
és szerkesztő oldaláról is indítható. Egy kiválasztott vagy az összes aktív,
HUF pénznemű kisker árlistára futtatható. Az árlistatörzs szezonfüggetlen;
a kalkuláció szezonját az árprojekt határozza meg. Ugyanaz az árlista
több szezonban is használható, a publikált árak szezonja a tételekhez tartozik.

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
- A végleges ár piros lefelé / zöld felfelé nyila jelzi az eltérést a kalkulálttól.
- Újraszámításkor a manuális és már átnézett / jóváhagyott végleges árak megmaradnak.
  Ha a kalkulált eredmény változik, a **Felülvizsgálandó** jelzés bekapcsol.
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
