# Kompakt oszlopválasztók

A HUF kisker próba után minden adminisztrációs oszlopválasztó ugyanazt az
elrendezést használja: törzsadatok, kapcsolódó listák, árképzési oldalak és a
partnerárlista-készítő terméklistája.

- Egy oszlop, kisebb függőleges térköz és keskenyebb panel.
- A választólista függőlegesen görgethető; a fejléc és az Alkalmazás gomb
  nem görgetődik el. A hosszú oszlopnevek több sorba törhetnek.
- Megmarad a sorrend húzással történő módosítása, a kijelölés és a felhasználói
  beállítások mentése, valamint az alkalmazás utáni automatikus bezárás.

A Filament saját oszlopkezelőjét használjuk, vendor-felülírás és új JavaScript
nélkül. A közös `AdminResourceTable::configureColumnManager()` adja az
elrendezést és az Alkalmazás utáni bezárást. Nem változtatja meg, hogy mely
oszlopok kapcsolhatók vagy rendezhetők át. A partnerárlista-készítő ugyanazt a
segédfüggvényt hívja, de megtartja a saját termékkijelölési eseménykezelőjét.
Az elmentett felhasználói beállítások kulcsai és tartalma nem változik.

## Ellenőrzés

```powershell
$previousColumnAppKey = $env:APP_KEY
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(('test' * 8)))
try {
    php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/PricingDecimalPreferencesTest.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/Admin/AdminAuthorizationTest.php --filter='^(?!.*(test_every_admin_resource_has_a_read_only_page_and_standard_table_features|test_every_managed_resource_and_page_has_authorization_enabled)).*$'
} finally {
    $env:APP_KEY = $previousColumnAppKey
}
```

A tesztek ellenőrzik minden saját admin-resource, az árképzési oldalak és a
partnerárlista-készítő egyoszlopos konfigurációját és HTML-jelölőjét, a mentett
beállításokat és az alkalmazás utáni bezárás eseménykezelőjét. Ez nem böngészős
vizuális ellenőrzés. A korábbról ismert két, fix resource-darabszámot váró tesztet
a fenti futtatás kihagyja; azok elvárásait nem módosítjuk.
Eredmény: **72 teszt, 762 ellenőrzés, sikeres**. A kódformázás és a Git
whitespace-ellenőrzés is sikeres.

## Telepítés – csak külön kérésre

Nincs új adatbázis-migráció, Composer-függőség, frontend build, manuális
fájlfeltöltés vagy további üzemeltetői beavatkozás. A CSS Gitből frissül;
a már meglévő fájlidő-alapú verziózás frissíti a böngésző gyorsítótárát.

A `.worktrees/pricing-module` könyvtárból:

```bash
git add -- app/Filament/Support/AdminResourceTable.php app/Filament/Pages/PartnerPriceListExport.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Pages/PricingProductOverview.php app/Filament/Resources/PricingCalculationRows/Tables/DistributorCalculationsTable.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php public/css/admin-table-scroll.css tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/PricingGridInteractionsTest.php docs/compact-column-manager.md
git commit -m "Use compact scrollable column choosers across admin tables"
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
