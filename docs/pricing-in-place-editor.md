# Helyben nyíló termékár-kezelő és egységes kalkulációs lista

## Változás

A bekerülési, HUF/devizás kisker-, nagyker-, disztribútori, teljes áttekintő és
általános kalkulációs listák **Műveletek → Termékenkénti árkezelés** pontja helyben
nyitja meg a kiválasztott modell teljes árkezelőjét. Nem navigálunk másik menübe.
A külön Termékenkénti árkezelés oldal ugyanazt a közös szerkesztőt használja.
A régebbi közvetlen linkek továbbra is működnek, de új menüpont nem használ ilyet.

A modal a kattintott sor projektjét és modelljét használja, nem egy korábbi
projektválasztást. A modell összes színváltozata, a tényleges paraméterek és a
függő árak továbbra is a már meglévő kalkulációs szolgáltatásból jönnek.
Bezáráskor a még nem mentett adatok törlődnek; a lista változatlan marad.
Mentéskor a listát és az átlagokat frissítjük, de **nem hívunk resetTable()-t**:
a szűrők, rendezés, lapozás, kijelölés és oszlopbeállítások nem állnak vissza.

Az általános **Árkalkulációs sorok** lista:

- Szállítás/vám: színezett szöveges cellák, folyamatos TextInputColumn nélkül.
- Dupla kattintás / Enter: csak az engedélyezett bekerülési sorok százalékai szerkeszthetők.
- A bevitel lezárásakor szűkítési szintet kérő modal nyílik, a megadott érték előtöltésével.
  A lehetőségek mellett megmarad az érintett sorok száma. Mégse esetén nincs mentés.
- A szállítási költség és vám módosításának szűkítésválasztója alapból **Modell**
  szintre áll, a bekerülési értékek gyorsszerkesztőjében és az árkalkulációs sorok
  modaljában is (dupla kattintásból és a műveletek menüből egyaránt).
  Minden új megnyitás modellszintről indul; a felhasználó más elérhető szintet is
  választhat. A paramétertörzsben meglévő rekordok szűkítése ettől nem változik.
- A sor más celláján dupla kattintás az ártípusnak megfelelő részletezést mutatja
  (bekerülési, kisker/outlet, nagyker, disztribútor).
- A korábbi Részletek, Szállítás %, Vám %, Szerkesztés és a termékár-kezelő
  a hárompontos Műveletek menübe került; a jogosulatlan műveletek nem jelennek meg.
- A meglévő szűrés, rendezés, Excel-export és tömeges törlés megmarad.

## Jogosultság és adatbiztonság

A helyben nyíló árkezelőhöz változatlanul kell a `View:PricingProductWorkspace`,
a kalkuláció listázási/megtekintési és létrehozási/módosítási, valamint a projekt
módosítási joga. A paraméterek és beszerzési árak módosítását továbbra is az
adatmódosító szolgáltatás saját jogosultságvizsgálata védi.

A modal megnyitásakor, előnézeténél és mentésénél is a kiinduló lista tényleges
projekt-/pénznem-/resource-szűkítésében keressük meg a forrássort. A forrássor,
projekt és modell azonosítói szerveroldalon zárolt Livewire-állapotok. Más projekt
sorával nem kerülhető meg a lap szűkítése. A bezárt modalból nem indítható előnézet.
Időközben lezárt projekt vagy visszavont jogosultság esetén nincs mentés.
A mentetlen előnézet továbbra is teljes egészében visszagörgetett tranzakció.

A szállítás/vám szerkesztését a meglévő CostCalculationAuthorization védi.
Nem bekerülési soron, lezárt projektben, illetve paraméter-/projekt-/kalkulációs
módosítási jog nélkül nem írható át a százalék. A kézi HTTP/Livewire-hívásokat is
vizsgáljuk, nem csak a cella vagy menüpont láthatóságát.

## Ellenőrzés

A kapcsolódó regressziós futtatás: **309 teszt, 2460 assertion**, sikeres.
A két korábbról ismert, fix admin-resource darabszámot váró tesztet kihagytuk,
nem módosítottuk az elvárásaikat. A végső célzott futtatás további **8 tesztje,
136 assertion** sikeres, beleértve az első mentéskor létrejövő új árlistaoszlopok
azonnali megjelenését is (szűrő- és lapozás-visszaállítás nélkül).

Teszteltük a helyben nyíló modalt minden érintett listán; a kijelölt modell/
projekt használatát; a Mégse és mentés utáni listaállapotot; a mentetlen előnézet
visszagördítését; a generikus dupla kattintásos százalékszerkesztést és szűkítési
szintet; a lezárás/jogosultság/forrásrekord-korlátokat; minden ártípus részletezőjét.
Ez PHP/Livewire- és renderelt HTML-ellenőrzés, nem teljes böngészős vizuális teszt.

## Telepítés

Új migráció, Composer install, npm build, kézi fájlfeltöltés, `.env`-módosítás
vagy új jogosultság nem szükséges. Új JavaScript-kód/bundle sincs: az eddigi
delegált dupla kattintás és műveletmenü nyitja meg a közös Filament-modalt.
A már meglévő jogosultságokat nem osztjuk ki automatikusan.

Git a `.worktrees/pricing-module` könyvtárból:

```bash
git add -- app/Filament/Pages/PricingCostCalculations.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Pages/PricingProductOverview.php app/Filament/Pages/PricingProductWorkspace.php app/Filament/Resources/PricingCalculationRows/Pages/ListPricingCalculationRows.php app/Filament/Resources/PricingCalculationRows/PricingCalculationRowResource.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php app/Filament/Support/ProductPricingNavigation.php app/Filament/Support/Concerns/HasProductPricingEditor.php resources/views/filament/resources/pricing-calculation-rows/list.blade.php tests/Feature/PricingCostDoubleClickTest.php tests/Feature/ProductPricingWorkspaceTest.php docs/project-wide-price-calculation.md docs/pricing-in-place-editor.md
git commit -m "Unify pricing grids and open product pricing modals in place"
git push origin feature/pricing-module
```

NAS, csak külön kért telepítéskor:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, csak külön jóváhagyott és az éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
