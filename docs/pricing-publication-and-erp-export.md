# Árprojekt lezárása és ERP árexport

## Hol található?

Az **Árképzés → Árprojektek** listájában és az árprojekt megtekintő/
szerkesztőoldalán:

- **Lezárás és árak publikálása**
- **Árprojekt visszanyitása** (lezárt projektnél, külön jogosultsággal)
- **ERP árexport (Excel)** (az első sikeres publikálástól)

A státusz mezővel többé nem lehet lezárni vagy visszanyitni. A szezon
létrehozás után nem cserélhető. Lezárt projekt és kalkulációs/paraméter/
kerekítési rekordjai a generikus admin űrlapokon sem írhatók, törlésüket
a tömeges műveleteknél is korlátozzuk. Publikált projekt nem törölhető.

## Publikálás

A lezárás egy adatbázis-tranzakcióban:

1. Zárolja és ellenőrzi a projektet és szezont.
2. Ellenőrzi a projekt aktív termékeinek aktív árlistához tartozó kalkulációit:
   pozitív végleges ár, jóváhagyott/módosított jóváhagyott/2–3. körös
   jóváhagyott státusz, nincs felülvizsgálati jelzés, aktuális számítási
   források. Az „Átnézett” állapot még nem jóváhagyás. Outletnél a minimum
   kedvezmény is kötelező. Nem hagy jóvá semmit automatikusan.
3. Termék–szezon–árlista szerint létrehozza/frissíti a `price_list_items`
   rekordokat, a végleges árat a meglévő `net_price` mezőbe írva. Retail és
   outlet esetén ez a partnerportál eddigi adatmodellje szerint bruttó ár.
   Más szezonok, termékek és nem publikált árlistatételek nem változnak.
4. Megőrzi a publikálás időpontját, végrehajtóját, a termékneveket/kódokat,
   bekerülési értékeket, árlistákat, árakat, forrássorazonosítókat és a
   felülírt korábbi árakat a `pricing_project_publications.snapshot` mezőben.
5. Lezárja a projektet (`closed_at`, `closed_by`). Bármilyen hiba esetén
   az egész tranzakció visszagördül, nincs részleges publikálás.

Az árlistatétel modellszintű. Az azonos modell/szezon/árlista színenkénti
azonos árait egy tételbe vonjuk össze. Eltérő színárnál, vagy színenként
eltérő/hiányzó HUF bekerülési értéknél a rendszer hibát jelez, nem választ
önkényes árat. A bekerülési értéknek nem kell külön jóváhagyási státusz,
de legyen pozitív, egyértelmű és felülvizsgálatmentes.
Beszerzési forrásból kalkulált bekerülési értéknél a lezárás újraellenőrzi
az aktív legolcsóbb jelöltet, a beszerzési árat, pénznemet, árfolyamot,
szállítási költséget és vámot is. Eltérésnél előbb újraszámítás szükséges.

Csak a már kiszámított, aktív termék/aktív árlista árakat publikáljuk.
A funkció nem talál ki hiányzó árszinteket, és nem törli azokat a meglévő
végleges tételeket, amelyekhez most nincs publikált kalkuláció.

Visszanyitáskor a publikált árak megmaradnak. Az új munkapéldány csak az
ismételt lezáráskor kerül a partnerportál végleges áraiba, új publikálási
verzióval. A régi pillanatképek megmaradnak; a visszanyitó és időpontja is
naplózott. A lezárt projekt adatbázisbeli közvetlen státuszmódosítása sem
kerülheti meg a visszanyitási jogosultságot az Eloquent modellen keresztül.

## Jogosultságobjektum

A szerepkörök **Egyéb / egyedi jogosultságok** lapján külön
**Árprojekt életciklus** jogosultságok:

- `Publish:PricingProjectLifecycle`: lezárás és publikálás.
- `Reopen:PricingProjectLifecycle`: visszanyitás.
- `Export:PricingProjectLifecycle`: ERP árexport.

A migráció létrehozza a jogosultságokat, de meglévő szerepkörökhöz nem
rendeli hozzá automatikusan. A super_admin az eddigi szabály szerint
hozzáfér. Az adminban a projekt lista/megtekintési joga továbbra is kell.
Az általános `Update:PricingProject` önmagában nem ad visszanyitást,
publikálást vagy ERP-exportot. `shield:generate --all` nem szükséges,
a meglévő policykat ne generáljuk felül.

## Fix ERP Excel

Az „AW27 HTL TÁSKA ÁRAK 20260909.xlsx” minta pontos **30 oszlopos**
struktúrája, `Import` munkalap, három fejlécsor, első adat a 4. sorban.
Nincs hozzáadott cím, lábléc, rejtett üzleti oszlop vagy további munkalap.

| Oszlop | Tartalom |
| --- | --- |
| A | Szezon nélküli modellkód; csak a projekt szezonkódjával egyező végződést vágjuk le |
| B | Modell magyar neve (hiányában angol) |
| C–E | Üres szezon, megnevezés és a minta üres elválasztója |
| F | HUF bekerülési érték, 2 tizedesre matematikailag kerekítve |
| G–H | HUF disztribútor: Eredeti / Lista ár |
| I–J | HUF nagyker: Eredeti / Lista ár |
| K–L | HUF kisker: Eredeti / Lista ár |
| M | HUF outlet Lista ár |
| N–O | EUR disztribútor: Eredeti / Lista ár |
| P–Q | EUR nagyker: Eredeti / Lista ár |
| R–S | EUR kisker: Eredeti / Lista ár |
| T–U | CZK disztribútor: Eredeti / Lista ár |
| V–W | CZK kisker: Eredeti / Lista ár |
| X | CZK outlet Lista ár |
| Y–Z | RON kisker: Eredeti / Lista ár |
| AA–AB | RON nagyker: Eredeti / Lista ár |
| AC–AD | PLN kisker: Eredeti / Lista ár |

Az Eredeti/Lista ár párok azonos végleges árakat tartalmaznak. Nincs
leárazás-kezelés, átváltás vagy ÁFA-számítás. Nem létező árszint üres,
nem nulla. Az árak valódi Excel-számok, a modellkód/nevek szövegek,
nem keletkezhet belőlük képlet. Ütköző szezon nélküli modellkód hibát jelez.

A kezdő dátum a letöltés ablakában megadható (alapból aznap), az A2
mezőbe a minta szerinti `YYYY.MM.DD` szövegként kerül. Ha egy árszinthez
pontosan egy publikált árlista van, azt ajánljuk fel. Több azonos
típusú/pénznemű listánál választani kell; más pénznem/típus nem helyettesíti.

**Az export a legutóbbi publikálási pillanatképből készül.** Visszanyitott
projektben is a már kiadott verziót adja, nem a módosítás alatt álló
kalkulációt. Első publikálás előtt nem készül ERP-export.

## Telepítés

### 2026.10.17 – Termékenkénti árkezelés és teljes áttekintés

- Minden Filament lista kijelölőoszlopa bal oldalon rögzített, a kijelölt
  sorokon is. A fejléc függőleges rögzítése megmarad. Közös kompakt sorstílus
  a paramétereknél és a többi törzsadatlistánál is.
- A szűrőikon mögött többválasztós főcsoport-, katalóguscsoport-, márka-,
  beszállító- és státuszszűrők jelennek meg, ahol a modell kapcsolatai ezt
  lehetővé teszik. Meglévő szűrőt nem duplázunk. A terméktörzs beszállítója
  az aktív beszerzési árakhoz kapcsolódik; a kalkulációé a kiválasztott beszállító.
- **Termékenkénti árkezelés**: kiválasztott árprojektben a termék sorára
  kattintva teljes szélességű szerkesztőablak nyílik. Aktív beszerzési árak,
  szállítás/vám, kisker/országszorzó, nagyker szorzó, disztribútori/outlet
  kedvezmény és manuális árak együtt kezelhetők. Több HUF forrásnál a
  devizás listához választani kell; nem választjuk ki önkényesen az elsőt.
- Minden átírt százalék/szorzó a kiválasztott árprojekt **adott modelljének**
  paramétere, nem ír át globális, ország- vagy főcsoportszintű adatot.
  A beszerzési ár ezzel szemben törzsadat: ugyanennek a modellnek más nyitott
  árprojektben használt kalkulációit felülvizsgálatra jelöljük. Az árfolyam
  és a kerekítési szabály továbbra is a meglévő szezon/árlista-beállításból jön.
- Bevitel közben előnézet fut a valódi kalkulátorokkal, mindig visszagördített
  tranzakcióban: nem marad mentett paraméter, ár, jóváhagyás vagy hibanapló.
  A mentett, új kalkulált és mentendő ár egymás mellett látható. Üres manuális
  mezőnél az új kalkulált ár kerül elfogadásra (a régi megtartott ár nem marad
  automatikusan érvényben); kitöltött mezőnél a megadott ár marad érvényben.
- **Minden ár mentése és jóváhagyása**: egy tranzakcióban, függőségi sorrendben
  bekerülés → HUF kisker → devizás kisker → nagyker → disztribútor/outlet.
  Bármely hibánál minden visszagördül. Időközben módosult adatoknál újranyitást
  kérünk; lejárt előnézet nem hagyható jóvá. Lezárt projekten nincs írás.
  Nem publikálunk `price_list_items` adatot: az továbbra is a projektlezárás feladata.
- **Teljes áráttekintés**: külön, csak olvasható lista modellenként/színenként,
  a tárolt végleges árakkal és használt paraméterekkel, köztes kerekítések nélkül.
  Pénznemek közti bekerülési összehasonlításhoz a kalkuláció mentett devizás
  kisker árfolyamát használjuk. Szorzók: ár HUF-ban / bekerülés; bruttó kisker /
  nettó nagyker; nettó nagyker / nettó disztribútor; bruttó kisker / nettó
  disztribútor. Nem számolunk implicit ÁFA-korrekciót. Hiányzó adat: üres,
  nem nulla. Oszlopok, sorrend, szélesség, tizedesek felhasználónként
  megjegyezve; minden ár és mutató szűrhető/rendezhető, Excelbe számként exportálható.
- Outlet: a kerekítés automatikusan a minimumkedvezményből adódó felső határ
  alatt tartja az új kalkulált árat, a korrekciót is figyelembe véve. A meglévő
  manuális vagy jóváhagyott ár nem változik észrevétlenül, továbbra is ellenőrizzük.

Új migráció: `2026_10_17_090000_add_product_pricing_page_permissions.php`.
Létrehozza a `View:PricingProductWorkspace` és `View:PricingProductOverview`
jogosultságot, de szerepkörhöz nem rendeli automatikusan. A super_admin
alapból látja. Más szerepkörnek az oldaljog mellett a kalkulációs sorok
listázási joga kell; íráshoz a projekt/sor/paraméter/beszerzési ár meglévő
módosítási jogosultságait is ellenőrizzük. A migráció az új kerekítés miatt a
**nyitott** projektek meglévő outlet sorait felülvizsgálatra jelöli. Lezárt
projektekhez és publikált árakhoz nem nyúl. A migráció visszavonása nem
töröl üzleti árat és nem szünteti meg automatikusan a felülvizsgálati jelzést.

Git a pricing worktree-ben (csak a felsorolt fejlesztési fájlok):

```bash
git add -- app/Filament/Pages/PricingProductOverview.php app/Filament/Pages/PricingProductWorkspace.php app/Filament/Support/AdminTableFilters.php app/Filament/Support/AdminResourceTable.php app/Filament/Resources/PricingRoundingRules/Schemas/PricingRoundingRuleForm.php app/Models/PricingParameter.php app/Services/Pricing/CostCalculationService.php app/Services/Pricing/HufRetailCalculationService.php app/Services/Pricing/ForeignRetailCalculationService.php app/Services/Pricing/OutletCalculator.php app/Services/Pricing/PricingRoundingService.php app/Services/Pricing/PricingOverviewQuery.php app/Services/Pricing/ProductPricingService.php public/css/admin-table-scroll.css resources/views/filament/pricing/product-workspace-editor.blade.php database/migrations/2026_10_17_090000_add_product_pricing_page_permissions.php tests/Feature/ProductPricingWorkspaceTest.php tests/Feature/OutletCalculationTest.php tests/Unit/OutletSafeRoundingTest.php docs/outlet-pricing.md docs/pricing-publication-and-erp-export.md
git add -- tests/Feature/ForeignRetailCalculationTest.php
git commit -m "Add single-product pricing workflow and comprehensive price overview"
git push origin feature/pricing-module
```

NAS csak külön kért telepítéskor:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, csak külön jóváhagyott és az éles ágba integrált release:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

`composer install`, `npm run build`, `.env`-módosítás és kézi fájlfeltöltés
nem kell. A CSS közvetlenül Gitből frissül, fájlidőbélyeges cache-verzióval.
Manuális teendő: nem-superadmin szerepkörökhöz az új oldaljogok hozzárendelése;
a nyitott projektek outlet árainak felülvizsgálata/újraszámítása és jóváhagyása.

Ellenőrzés: 286 kapcsolódó regressziós teszt, 2199 assertion sikeres; a
végső célzott futtatás 20 tesztje, 316 assertion szintén sikeres. A két
korábbról ismert admin-resource darabszám-elvárást a regressziós futtatásból
kihagytuk, nem módosítottuk. Az új szűrőkkel minden admin-resource lista
betöltését külön teszteljük, fix darabszám helyett a tényleges resource-listából.
Teszteltük a teljes tranzakció visszagördülését, az időközbeni módosítás
észlelését, a readonly/jogosultság-határokat, az Excel numerikus cellatípusait,
az új szorzókat és a valódi publikálási validátort is. A helyi böngészős
ellenőrzéshez ebben a munkamenetben nem volt elérhető böngésző; a renderelt
HTML és a Livewire-műveletek ellenőrzöttek, tényleges vizuális QA még szükséges.

### Jóváhagyásra / felülvizsgálatra váró sorok jelölése

A HUF és devizás kisker-, nagyker-, disztribútori, bekerülési és általános
kalkulációs listák halványpiros háttérrel jelzik a figyelmet igénylő sorokat,
világos és sötét témában is. Piros a sor, ha felülvizsgálandó, nincs pozitív
végleges ára, vagy még nem jóváhagyott (az „Átnézett” még nem jóváhagyás).
A bekerülési értéknek nincs külön jóváhagyási lépése, ezért ott önmagában a
„Kalkulált” státusz nem figyelmeztetés. A kisker listákon a konfigurált,
hozzátartozó outlet sorok állapota és minimumkedvezménye is számít.
A disztribútori listán a kiválasztott disztribútori sor státuszát nézzük,
nem a táblázat alapjául szolgáló nagyker sorét. A szerkeszthető cellák kék
jelölése megmarad. A színezés nem módosít adatot, nem végez újraszámítást
és nem váltja ki a lezárás teljes érvényesség-ellenőrzését.

Ehhez a megjelenítési változáshoz nincs új migráció, Composer install,
npm build, kézi feltöltés, új jogosultság vagy egyéb üzemeltetői teendő.
A közvetlenül kiszolgált CSS Gitből frissül; URL-jét fájlidőbélyeg verziózza.

Git a pricing worktree-ben:

```bash
git add -- app/Models/PricingCalculationRow.php app/Filament/Support/PricingGrid.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php public/css/admin-table-scroll.css tests/Unit/PricingAttentionTest.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/OutletCalculationTest.php tests/Feature/PricingCostDoubleClickTest.php docs/pricing-publication-and-erp-export.md
git commit -m "Highlight pricing rows pending approval or review"
git push origin feature/pricing-module
```

NAS (csak kért telepítéskor):

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, külön jóváhagyott és az éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

### A publikálás és ERP-export eredeti telepítése

Migráció szükséges: `2026_10_16_160000_add_pricing_publications.php`.
Az előző outlet fejlesztés migrációja is szükséges, ha még nincs telepítve.
Composer/npm build/kézi assetfeltöltés nem kell. A migráció üres naplót és
nullable metaadatokat hoz létre, nem zár le és nem publikál projektet.

Git a pricing worktree-ben, a változtatások ellenőrzése után:

```bash
git add -- app config/filament-shield.php database/migrations/2026_10_16_140000_add_outlet_pricing.php database/migrations/2026_10_16_160000_add_pricing_publications.php resources/views lang tests/Feature docs
git commit -m "Add outlet pricing, project publication and fixed ERP export"
git push origin feature/pricing-module
```

NAS: `infrastructure/scripts/deploy-nas.ps1` (pull, migrate --force, cache).
Éles WePanel, csak külön jóváhagyott release után a kiadási ág könyvtárában:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Manuális teendő: megfelelő szerepkörök egyedi jogosultságainak beállítása,
majd árak jóváhagyása, a projekt lezárása, és ERP-export. A fejlesztői ágat
nem húzzuk közvetlenül élesre. A saját ERP-be történő tényleges importot
itt nem futtatjuk; az export adat- és struktúraegyezését tesztekkel ellenőrizzük.
