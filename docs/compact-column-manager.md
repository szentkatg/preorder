# Kompakt oszlopválasztó – HUF kisker pilot

Csak a **HUF kisker árak** menü oszlopválasztója változik:

- Egy oszlop, kisebb függőleges térköz és keskenyebb panel.
- A választólista függőlegesen görgethető; a fejléc és az Alkalmazás gomb
  nem görgetődik el. A hosszú oszlopnevek több sorba törhetnek.
- Megmarad a sorrend húzással történő módosítása, a kijelölés és a felhasználói
  beállítások mentése, valamint az alkalmazás utáni automatikus bezárás.
- A devizás kiskerárak és a többi menü egyelőre nem változik.

A Filament saját oszlopkezelőjét használjuk, vendor-felülírás és új JavaScript
nélkül. A stílus kizárólag a HUF oldalon elhelyezett adatjelölő alatt érvényes.

## Ellenőrzés

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/PricingDecimalPreferencesTest.php
```

A tesztek ellenőrzik a HUF oldal egyoszlopos konfigurációját és HTML-jelölőjét,
a devizás oldal változatlanságát, a mentett beállításokat és az alkalmazás
utáni bezárás eseménykezelőjét. Ez nem böngészős vizuális ellenőrzés.
Eredmény: **24 teszt, 272 ellenőrzés, sikeres**.

## Telepítés – csak külön kérésre

Nincs új adatbázis-migráció, Composer-függőség, frontend build, manuális
fájlfeltöltés vagy további üzemeltetői beavatkozás. A CSS Gitből frissül;
a már meglévő fájlidő-alapú verziózás frissíti a böngésző gyorsítótárát.

A `.worktrees/pricing-module` könyvtárból:

```bash
git add -- app/Filament/Pages/PricingHufRetailCalculations.php public/css/admin-table-scroll.css tests/Feature/PricingGridInteractionsTest.php docs/compact-column-manager.md
git commit -m "Pilot compact scrollable column chooser on HUF retail page"
git push origin feature/pricing-module
```

NAS:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel: csak külön engedélyezett, éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
