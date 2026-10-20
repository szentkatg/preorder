# Árprojekt termékkijelölése és anyagösszetétel szerinti paraméterezés

## Használat

1. **Árprojektek → Új árprojekt**: szezon, név és státusz megadása.
2. **Létrehozás és termékek kiválasztása**: a program a termékkiválasztóhoz lép.
3. Csak a projekt szezonjához tartozó, még egyetlen árprojektbe sem felvett
   termékek látszanak. Márkára és rendelőlap típusra többválasztós szűrő van;
   modellkódra, névre és anyagösszetételre keresni is lehet.
4. Kijelölés után **Kijelölt termékek felvétele**, majd megerősítés.
   Az árak ettől még nem számolódnak ki. Több ütemben is felvehetők termékek.
5. Vissza a projekthez: **Árprojekt termékei** fül mutatja a teljes tagságot,
   akkor is, ha még nincs kalkuláció. Innen újra megnyitható a termékválasztó.

Az inaktív termékek is kijelölhetők, állapotuk külön látszik, de a kalkulációk
továbbra is csak aktív termékekkel dolgoznak. Lezárt projekt termékei nem
változtathatók és azok termékei sem kínálhatók fel másik projektben.

Az új `pricing_project_products` kapcsolat a teljes modellre vonatkozik,
tehát annak minden színére. A `product_id` adatbázis-szinten is egyedi.
A felvétel tranzakcióban, projekt- és termékzárolással történik; minden
kijelölt elem újbóli ellenőrzése után írunk, részleges felvétel nincs.

A bekerülési érték, a projekt teljes újraszámítása és minden további árszint
a tagságra szűr. A termékenkénti szerkesztő és a kézi kalkulációs űrlap sem
enged kívülálló terméket feldolgozni. Publikáláskor is ellenőrizzük a tagságot.
Üres projekt **nem** jelenti a teljes szezon kiválasztását.

### Termékek kivétele

**Árprojektek → Szerkesztés → Árprojekt termékei**: kijelölés után
**Kijelölt termékek kivétele**. Figyelmeztetés és megerősítés után törlődik
a tagság és a kijelölt modellek valamennyi kalkulációja, minden színre,
pénznemre és árszintre. A már publikált `price_list_items` és a publikálási
snapshotok változatlanok. A terméktörzs, beszerzési árak és paraméterek is
megmaradnak. Az újraszámítás nem veszi vissza automatikusan a terméket.
A kalkulációs sorok régi, modell–szín szintű törlése nem egyenlő a modell
projektből való kivételével.

Jogok: `ViewAny:PricingProject`, `View:PricingProject`, `Update:PricingProject`,
`ViewAny:Product`, és a kijelölt termékek `View:Product` joga. Kivételhez kell
`DeleteAny:PricingCalculationRow` és minden érintett ársor `Delete` joga is.
Lezárás és jogosultság mentéskor újra ellenőrződik. Új jogosultságobjektum nincs.

## Anyagösszetétel

Az árképzési paraméterek új szűkítése: **Anyagösszetétel**. A terméktörzsben
szereplő teljes érték választható ki; nincs részszöveg- vagy százalékértelmezés.
Az anyagösszetételt egységesen érdemes rögzíteni a terméktörzsben.

Elsőbbség: **globális → beszállító országa → főcsoport → anyagösszetétel → modell**.
Ez valamennyi paramétertípusnál működik. Árlista és pénznem szerinti további
szűkítés változatlan. A szállítás/vám dupla kattintásos szerkesztőben is
választható, az érintett kalkulációs sorok számával együtt. Üres anyagösszetételnél
nem kínáljuk fel. A paramétermásolás felismeri az anyagösszetétel szerinti
azonosságot/ütközést; a kalkulációs snapshotok is tárolják ezt a szűkítést.

## Meglévő adatok és kötelező ütközésellenőrzés

A felhasználó döntése szerint a korábbi, több projektben szereplő termékeket
**előbb kézzel kell rendezni**. A migráció nem választ tulajdonosprojektet,
nem von össze és nem töröl kalkulációkat.

Az ellenőrzés a jelenlegi kalkulációs sorokat tekinti projekt-hozzárendelésnek,
nem a változtathatatlan történeti publikálási snapshotokat. Történeti publikáció
nem kerül átírásra vagy törlésre akkor sem, ha később kivették a modellt.
Az egyértelmű régi hozzárendelések automatikusan bekerülnek az új táblába.
Azok a szezontermékek, amelyekhez még nincs kalkuláció, nem kapnak automatikus
tagságot; szükség esetén a termékválasztóban fel kell venni őket.

Az új ellenőrző parancs csak olvas:

```bash
php artisan pricing:check-product-assignments
```

Ütközésnél modellkódot, termék- és projektazonosítót, projektnevet és státuszt
listáz, és hibakóddal tér vissza. A migráció szintén megáll, **még a sémamódosítás
előtt**. A kiválasztott tulajdonosprojekt megtartandó, a többi projekt megfelelő
modell–szín kalkulációit a meglévő felületen kézzel kell kivenni/törölni.
Lezárt projekt ehhez csak megfelelő jogosultsággal nyitható vissza.
Publikált árat vagy snapshotot emiatt nem szabad szerkeszteni.

### NAS frissítés

A közös NAS-frissítő az ellenőrzést **még a futó kód frissítése előtt** elvégzi,
az új parancs NAS-ra telepítése nélkül is. Ütközésnél nem változtatja meg a NAS
fájljait, adatbázisát vagy árait. A `-CheckOnly` mód is ellenőriz. Ütközésnél
a frissítést meg kell szakítani és előbb egyeztetni a kézi rendezést.

## Telepítési igény

- Migráció: **igen** (`2026_10_19_140000_add_material_scope_and_pricing_project_products`).
- Composer install: nem (ha a kiadott release más része sem módosít függőséget).
- npm build: nem.
- Manuális assetfeltöltés / `.env`: nem.
- Üzemeltetői feladat: az esetleges régi ütközések kézi rendezése **frissítés előtt**;
  a megfelelő meglévő jogosultságok kiosztása.
- NAS és éles telepítés csak külön kérésre.

A pricing worktree-ben, a korábbi paramétermásolással együtt, a változások
ellenőrzése után (a `.tmp` nem kerül bele):

```bash
git add -- app/Console/Commands/CheckPricingProductAssignments.php app/Filament/Pages/PricingCostCalculations.php app/Filament/Resources/PricingCalculationRows app/Filament/Resources/PricingParameters app/Filament/Resources/PricingProjects app/Filament/Resources/PricingRoundingRules app/Filament/Support/Actions/CopyPricingConfigurationAction.php app/Filament/Support/Concerns/GuardsPricingProjectWrites.php app/Models/PricingParameter.php app/Models/PricingProject.php app/Models/Product.php app/Services/Pricing database/migrations/2026_10_19_140000_add_material_scope_and_pricing_project_products.php infrastructure/scripts/deploy-nas.ps1 resources/views/filament/pricing/outlet-calculation-details.blade.php resources/views/filament/pricing/retail-calculation-details.blade.php resources/views/filament/resources/pricing-projects tests/Feature/DatabaseMigrationTest.php tests/Feature/DistributorCalculationTest.php tests/Feature/ForeignRetailCalculationTest.php tests/Feature/HufRetailCalculationTest.php tests/Feature/PricingAuthorizationTest.php tests/Feature/PricingCostDoubleClickTest.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/PricingPayloadPerformanceTest.php tests/Feature/PricingProjectContextTest.php tests/Feature/PricingProjectPublicationTest.php tests/Feature/ProductPricingWorkspaceTest.php tests/Feature/WholesaleCalculationTest.php tests/Feature/PricingConfigurationCopyTest.php tests/Feature/PricingMaterialScopeTest.php tests/Feature/PricingProjectProductsTest.php docs/pricing-configuration-copy.md docs/pricing-project-products.md docs/pricing-workflow-and-item-deletion.md
git commit -m "Add explicit project product selection and material pricing scope"
git push origin feature/pricing-module
```

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel: **külön engedélyezett és integrált release** esetén,
adatbázismentéssel és előzetes ütközésellenőrzéssel. Az előellenőrzést még a régi
kóddal is el kell végezni (a NAS-frissítő csak a NAS-t védi). Kódfrissítés után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan pricing:check-product-assignments
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize
```

## Tesztek

Ellenőrzés: **374 regressziós teszt, 3015 ellenőrzés, sikeres**. Az új funkciók
19 célzott tesztje is sikeres (91 ellenőrzés; részben átfed a regresszióval).
A két korábbról ismert, fix admin-resource darabszámot váró teszt kimaradt;
azok elvárásait nem módosítottuk. Pint és `git diff --check` sikeres.
A frissítő PowerShell-kódja és a beágyazott PHP szintaktikailag ellenőrzött;
távoli NAS-frissítés ebben a körben nem történt.

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingProjectProductsTest.php tests/Feature/PricingMaterialScopeTest.php
```

PHP/Livewire/renderelt HTML ellenőrzés történik; nem böngészős vizuális próba.
SQLite tesztek ellenőrzik az egyedi adatbáziskulcsot és a tranzakciókat;
a MySQL párhuzamos kéréseinek helyességét termékzárolás és egyedi kulcs védi,
élő MySQL konkurenciateszt ebben a fejlesztési környezetben nem történt.
