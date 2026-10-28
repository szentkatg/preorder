# Közös termék-, szín- és katalóguscsoport-nevek

## Hatókör

A terméknevek, színnevek és katalóguscsoport-nevek nem fordítandó üzleti adatok.
Minden felületi és exportnyelven a közös érték jelenik meg:

| Tábla | Megmaradó közös mező | Eltávolított duplikátum |
| --- | --- | --- |
| products | name_hu | name_en |
| products | catalog_group_name_hu | catalog_group_name_en |
| colors | name_hu | name_en |

A megmaradó oszlopokat most nem nevezzük át. Az admin felirata **Név**, illetve
**Katalóguscsoport**, nyelvi utótag nélkül. A termékek és színek nem kerülnek be a
`translations` rendszerbe. A többi törzs további takarítását és az árlisták
központi fordítását a `reference-name-cleanup.md` írja le, külön migrációval.

A 2026-10-28-i csak olvasási adatellenőrzés szerint a NAS-on 889 termék és 3039
szín, az élesen 571 termék és 1935 szín összes érintett mezőpárja pontosan egyezett.
Az inaktív színek is szerepeltek az ellenőrzésben. Eltérés és üres érték nem volt.

## Működés és kompatibilitás

- A partner-rendelőlap, összesítő, megosztott rendelőlap, képnézegető és keresés
  minden nyelven a közös neveket használja. A katalóguscsoport URL-ekben is
  a közös név szerepel; az ellenőrzött adatoknál ez nem változtatja meg a régi URL-eket.
- A partnerárlista Excel/PDF és előnézet terméknevei/csoportnevei közösek;
  a fejlécek, felületi szövegek és fordítható törzsek nyelve továbbra is választható.
- Az ERP-export és az új publikációs pillanatképek a közös terméknevet használják.
  A régi publikációk JSON-adatait nem írjuk át; egy bennük megmaradt `name_en`
  kulcs nem adatbázisoszlop-hivatkozás.
- A termék- és színszerkesztőből, listákból és tömeges termékmódosításból
  kikerültek az angol névmezők. A régi mezőkre célzott tömeges módosítást elutasítjuk.
- A terméktörzs import fejlécei maradnak `name_hu` és
  `catalog_group_name_hu`; az oszlopokat név, nem pozíció alapján azonosítjuk.
  Új fájlban nem kell `name_en` / `catalog_group_name_en` oszlop.
  Régi fájlban a hiányzó, üres vagy a közös értékkel egyező angol cellákat elfogadjuk,
  de nem írjuk adatbázisba. Importnál a környező szóközöket figyelmen kívül hagyjuk.
  Eltérő, nem üres angol cella sorhibát okoz: az egész érintett modell és hozzá
  kapcsolódó sorai kimaradnak, a többi hibátlan modell importja megmarad.

## Migráció és visszaállítás

`2026_10_28_130000_drop_redundant_product_and_color_english_names.php`

A migráció először **mindhárom mezőt végigellenőrzi minden rekordnál**, és csak
utána töröl oszlopot. Bármely nem üres angol értéknek bájtpontosan egyeznie kell a
közös mezővel; a kis/nagybetű- és szóközeltérés is megállítja. Nem támaszkodik a
MySQL kis/nagybetűt esetleg figyelmen kívül hagyó kollációjára. Eltérésnél táblát,
rekordazonosítót és mezőnevet ír ki; ilyenkor nem töröl oszlopot. Üres angol mező
nem akadály. A migráció nem változtat árakon, rendeléseken vagy a megmaradó neveken.

Visszagörgetéskor a három régi oszlop újra létrejön, és az **akkori közös értékekből**
töltődik fel. Ez nem egy korábbi adatbázismentés visszatöltése. Pontos korábbi
állapothoz az élesítés előtt készített mentés szükséges. Régi alkalmazáskód
visszaállítása előtt előbb a régi oszlopokat kell visszaállítani, maintenance alatt.
MySQL-en a több DDL művelet nem atomi: a preflight az adatok miatti részleges
törlést előzi meg, infrastruktúrahiba esetén a mentésből történő helyreállítás kellhet.

## Ellenőrzés

Csak elkülönített tesztadatbázisban:

```powershell
php -d extension=pdo_sqlite -d memory_limit=1024M infrastructure/scripts/test-worktree.php tests/Feature/SharedProductNamesMigrationTest.php tests/Feature/Admin/ProductMasterImportTest.php tests/Feature/Admin/ProductBulkUpdateTest.php tests/Feature/Partner/PartnerSeasonVisibilityTest.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/PricingProjectPublicationTest.php tests/Feature/ErpPricingFormulaExportTest.php tests/Feature/DatabaseMigrationTest.php
```

Friss séma, guard és rollback, régi/új importfájl, konfliktusos modell kihagyása,
HU/EN partner- és megosztott felület, keresés, Excel/PDF és ERP-export szerepel a
tesztekben. A migráció tesztje az inaktív szín eltérését is ellenőrzi.

## Kihelyezés – csak külön kérésre

**Migráció szükséges. Composer install és npm build nem szükséges.
Nincs kézi fájlfeltöltés vagy .env-módosítás. Rövid maintenance és előzetes
adatbázismentés szükséges**, mert oszlopokat törlünk. A korábbi kompakt partnerárlista
UI módosításai jelenleg ugyanebben a munkafában vannak; commit előtt a teljes diffet
ellenőrizni kell. A `.tmp/` és `output/` ellenőrző fájlok nem kerülhetnek Gitbe.

Git a pricing worktree-ben, ellenőrzött célzott staging után:

```powershell
git diff --stat
git add -- app/Models/Product.php app/Models/Color.php app/Livewire/Partner/CatalogGroupOrder.php app/Livewire/Partner/OrderSelector.php app/Livewire/Partner/OrderSummary.php app/Services/PartnerPriceListService.php app/Services/ProductBulkUpdateService.php app/Services/Pricing/ErpPricingExportService.php app/Services/Pricing/PricingProjectPublicationService.php app/Filament/Pages/PartnerPriceListExport.php app/Filament/Pages/ProductMasterImport.php app/Filament/Resources/Products/Schemas/ProductForm.php app/Filament/Resources/Products/RelationManagers/ColorsRelationManager.php app/Filament/Resources/Colors/Tables/ColorsTable.php app/Filament/Resources/ProductPurchasePrices/Schemas/ProductPurchasePriceForm.php database/migrations/2026_10_28_130000_drop_redundant_product_and_color_english_names.php tests/Feature/SharedProductNamesMigrationTest.php tests/Feature/Admin/ProductMasterImportTest.php tests/Feature/Admin/ProductBulkUpdateTest.php tests/Feature/Partner/PartnerSeasonVisibilityTest.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/ErpPricingFormulaExportTest.php tests/Feature/DatabaseMigrationTest.php docs/shared-product-names.md docs/partner-price-list-export.md resources/views/filament/pages/partner-price-list-export.blade.php
git commit -m "Use shared product and color names and remove redundant English columns"
git push origin feature/pricing-module
```

A NAS deployment script migrál és cache-t újraépít, de nem készít mentést és nem
kapcsol maintenance módot: ezeket külön kell előkészíteni a jóváhagyott kihelyezéskor.

WePanel/korábbi cPanel: csak a külön jóváhagyott éles kiadásba integrálás után,
a projekt könyvtárában, kész adatbázismentéssel:

```bash
/opt/alt/php83/usr/bin/php artisan down --retry=30
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize
# Csak sikeres ellenőrzés után:
/opt/alt/php83/usr/bin/php artisan up
```

Hiba esetén nem szabad automatikusan visszakapcsolni az alkalmazást vagy régi kódra
váltani a séma helyreállítása nélkül. Ha futnak hosszú életű queue-workerek,
azokat a jóváhagyott telepítési eljárással újra kell indítani.
