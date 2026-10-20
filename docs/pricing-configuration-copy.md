# Árprojekt-paraméterek és kerekítési szabályok másolása

## Kiterjesztés valamennyi adminlistára (2026-10-20)

A listaállapot és a szűrőpanel bezárása most központi Filament-táblabeállítás:
`AppServiceProvider` → `Table::configureUsing()` → `AdminTableState`.
Nem szükséges külön az `AdminResourceTable` segédet használnia egy listának.
Vonatkozik minden admin-resource listájára, a szerepkörkezelő plugin listájára,
az egyedi adminoldalak táblázataira, a kapcsolódó listákra és a TableWidgetekre
is. Az új adminlisták automatikusan öröklik az alapbeállítást.

A beállítás kizárólag az **admin** panel Filament-oldalaihoz, relation
managerjeihez és TableWidgetjeihez tartozó táblákat érinti. A partnerportál,
az adminpanelen kívüli és a sima Livewire-táblák alapbeállítása változatlan.
Nincs új jogosultság vagy automatikus jogosultságmódosítás.

Az árprojekt termékválasztójában a sikeres tömeges felvétel után is megmarad
a márka/rendelőlap-típus szűrés és keresés. A partnerárlista-készítő adminoldal
terméktáblája előnézetfrissítéskor sem törli a keresést/rendezést. A felvehető
termékek és az exporthoz kijelölt termékek továbbra is az aktuális lekérdezést
követik; kijelölést vagy exportműveletet nem állítunk vissza automatikusan.
Ez a táblázatállapot megőrzése; az exportfájl konfigurációja továbbra is külön
űrlap és külön munkafolyamat. A központi beállítás önmagában nem menti annak
szezon/árlista/nyelv mezőit.

Az Alkalmazás gomb a lenyíló szűrőpanelen a szerverhívás után zárja be a panelt.
Beágyazott szűrőelrendezésnél nincs ablakbezárás; a modális szűrők natív
Filament-bezárása változatlanul működik. A szűrőtörlés és a kifejezett
árprojekt-/pénznemváltás változatlanul működik. A megőrzés munkamenet-alapú,
listánként külön kulccsal, nem új adatbázisos felhasználói preferencia.

### Ellenőrzés és kiadás

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/Admin/AdminListStateTest.php tests/Feature/PricingProjectProductsTest.php tests/Feature/PartnerPriceListExportTest.php
```

Az új dinamikus teszt a regisztrált resource-ok számának rögzítése nélkül
ellenőrzi valamennyi indexlistát, a plugin szerepkörlistáját is. Külön teszt
ellenőrzi az alábontott színlista Mentés/Mégse viselkedését, a termékválasztó
felvétel utáni szűrését, az admin exportlista frissítését és az adminon kívüli
Livewire-táblák változatlanságát. A korábbi két, rögzített resource-darabszámot
váró teszt elvárásait nem módosítjuk.

Végső ellenőrzés: **124 teszt, 1357 ellenőrzés**, sikeres (54/579 és
70/778). A jogosultsági HTTP-tesztekhez csak a tesztfolyamatban adtunk meg
egy mesterséges `APP_KEY` értéket; `.env`-et és szerverkonfigurációt nem
módosítottunk. A két korábban elhalasztott fix darabszámteszt kimaradt.
Pint és `git diff --check` sikeres. Élő böngészős vizuális QA nem történt.

A korábbi teljes csomag `git add` parancsa után az új kiegészítéseket is fel
kell venni; a `.tmp` könyvtár továbbra sem része a csomagnak:

```powershell
git add -- app/Filament/Support/AdminTableState.php app/Filament/Support/AdminResourceTable.php app/Providers/AppServiceProvider.php app/Filament/Pages/PartnerPriceListExport.php app/Filament/Resources/PricingProjects/Pages/SelectPricingProjectProducts.php tests/Feature/Admin/AdminListStateTest.php tests/Feature/PricingProjectProductsTest.php tests/Feature/PartnerPriceListExportTest.php docs/pricing-configuration-copy.md
git commit -m "Apply persistent table filters and closing filter panels to all admin lists"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Migráció, Composer install, npm build, manuális feltöltés és új jogosultság
**nem szükséges**. Telepítéskor cache-törlés/-építés szükséges. NAS- vagy éles
kihelyezés nem része ennek a kérésnek. Éles WePanel/cPanel, csak engedélyezett,
éles ágba integrált csomag után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Lista-szűrők és visszatérés (2026-10-20)

Az Árképzési paraméterek listája három új, kereshető, többválasztós szűrőt
kapott: **Árprojekt**, **Paraméter**, **Szűkítés**. Az egy szűrőn belüli
értékek VAGY, a különböző szűrők ÉS kapcsolatban működnek. A paraméterek
és szűkítések magyar választékát az űrlap és a szűrők közösen használják;
az Anyagösszetétel is szerepel a szűkítések között.

A közös `AdminResourceTable`-t használó adminlisták listánként, az aktuális
böngészős munkamenetben megőrzik az alkalmazott szűrőket, általános keresést,
oszloponkénti keresést és rendezést. Mentés vagy Mégse után, illetve a listát
újra megnyitva ezek visszatöltődnek. Ez nem több eszközre vagy új bejelentkezésre
szóló adatbázisos preferencia. A még nem alkalmazott szűrőpanel-módosítások nem
írják felül az alkalmazott beállítást. Különböző listák állapota nem keveredik.

A szokásos admin-adatlapok Mentés után az indexlistára térnek vissza. A külön
megírt létrehozási útvonalak (például árprojekt után a termékkiválasztás,
beszállító után annak szerkesztése) továbbra is a saját átirányításukat használják.
A Mégse változatlanul a korábbi oldalra navigál, mentés nélkül.

Az árképzési táblák frissítése több helyen korábban `resetTable()`-t használt,
amely a szűrőűrlapot is alaphelyzetbe állítja. A mentés/jóváhagyás,
százalékbeállítás Mégse, párosítás és újraszámolás utáni frissítések most
`refreshPreservingState()`-et használnak. A kézi szűrőtörlés továbbra is
töröl, és az explicit projekt-/archívum-/pénznemváltás sem tölti vissza a
korábbi környezet szűrőit. A dashboard célzott státuszszűrése továbbra is
elsőbbséget kap a korábbi munkamenet-állapottal szemben.

A közös listák lenyíló szűrőpaneljén az **Alkalmazás** gomb megvárja az
`applyTableFilters()` szerverhívást, utána a panel saját `close()` metódusával
bezár. A táblázatba beágyazott szűrőelrendezés nem kap ilyen bezáró hívást.
Nincs vendor-módosítás, új frontend csomag vagy Node-build.

### Ellenőrzés / telepítés

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/Admin/AdminListStateTest.php tests/Feature/PricingCostDoubleClickTest.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/PricingProjectContextTest.php tests/Feature/PricingWorkflowTest.php
```

Ellenőrzés: két végső, egymást nem átfedő tesztfutásban **184 teszt,
1524 ellenőrzés**, sikeres (106/818 és 78/706). A szűrők kombinálását,
modal Mentés/Mégse, külön adatlap utáni visszatérést, munkamenetes
visszatöltést, szűrőtörlést, inline százalék- és manuálisár-mentést,
projektváltást és dashboard-státuszlinkeket is ellenőrzik. A panelbezárás
PHP/Livewire és renderelt Alpine-handler szinten ellenőrzött; élő böngészős
vizuális QA nem történt. Pint és `git diff --check` sikeres.

Migráció, `composer install`, `npm run build`, manuális feltöltés és új
jogosultság kiosztása **nem szükséges**. A panelbeállítások miatt telepítéskor
a szokásos cache-törlés/-építés szükséges. A partnerportál változatlan.
Az előző ütközésvédelmi módosítások is helyben, még nem commitolva maradtak.
Telepítés csak külön kérésre.

Az aktuális, ellenőrzött teljes változáscsomag Git-parancsai (a korábbi
ütközésvédelemmel együtt, a `.tmp` tesztmappát kihagyva):

```powershell
git add -- app/Exceptions/PricingParameterConflictException.php app/Filament/Pages/PricingCostCalculations.php app/Filament/Pages/PricingForeignRetailCalculations.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Pages/PricingProductOverview.php app/Filament/Resources/PricingCalculationRows/Tables/HufRetailCalculationsTable.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php app/Filament/Resources/PricingParameters/Schemas/PricingParameterForm.php app/Filament/Resources/PricingParameters/Tables/PricingParametersTable.php app/Filament/Support/Actions/CalculateDistributorAction.php app/Filament/Support/Actions/CalculateForeignRetailAction.php app/Filament/Support/Actions/CalculateHufRetailAction.php app/Filament/Support/Actions/CalculateWholesaleAction.php app/Filament/Support/Actions/DistributorReviewActions.php app/Filament/Support/Actions/PairPreviousSeasonProductAction.php app/Filament/Support/AdminResourceTable.php app/Filament/Support/Concerns/GuardsPricingProjectWrites.php app/Filament/Support/Concerns/HasPricingGridInteractions.php app/Filament/Support/Concerns/HasPricingProjectContext.php app/Filament/Support/Concerns/HasPricingVatComparison.php app/Models/PricingParameter.php app/Providers/Filament/AdminPanelProvider.php app/Services/Pricing/PricingConfigurationCopyService.php tests/Feature/Admin/AdminListStateTest.php tests/Feature/PricingParameterValidationTest.php tests/Feature/PricingConfigurationCopyTest.php tests/Feature/PricingCostDoubleClickTest.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/WholesaleCalculationTest.php tests/Feature/OutletCalculationTest.php docs/pricing-configuration-copy.md
git commit -m "Preserve admin list filters and validate pricing parameter identities"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

WePanel/cPanel, kizárólag külön engedélyezett és az éles ágba integrált kiadásnál:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Mentéskori ütközésvédelem (2026-10-20)

Az árképzési paraméterek létrehozásakor, módosításakor és újraaktiválásakor
egy projektben ugyanarra a tényleges számítási kulcsra csak egy aktív rekord
menthető. Azonos érték is duplikáció; az inaktív változatok megmaradhatnak.
A mentett rekord önmagával nem ütközik. Az ellenőrzés az admin űrlapon,
a listabeli szerkesztőablakban és a kalkulációkból történő paramétermentésnél
is érvényes. A hibaüzenet megmutatja az ütköző rekordazonosítókat.

A kulcs: **projekt + paramétertípus + szűkítési típus + a kiválasztott
szűkítés értéke + árlista + pénznem**. Anyagösszetételnél a teljes érték
számít, nem részszöveg. Eltérő anyagösszetételek és eltérő szűkítési szintek
nem ütköznek. A kalkulációk elsőbbségi sorrendje változatlan.

Mentéskor a más szűkítésekhez tartozó mezőket nullázzuk, az országkódot
nagybetűsítjük. A régi sorok rejtve maradt, nem használt mezői sem jelentenek
külön kulcsot: például egy Globális sorban maradt CN nem országos szabály.
A másolás és a mentés közös azonosságvizsgálata adatbázis-lekérdezést használ,
így az anyagösszetétel egyezése az adatbázis kollációjához igazodik, ahogy a
kalkulációban is.

Másoláskor megmarad az előzetes ütközéslista, valamint a kötelező felülírás/
kihagyás választás. Többszörös céloldali kulcsot nem vonunk össze; az csak
kihagyható, amíg kézzel nincs rendezve. A megerősítés utáni ellenőrzés is
lefut, a tömeges művelet tranzakciós. A normál Eloquent-paramétermentések
projektzárolás mellett ellenőriznek és írnak. A nyers SQL és a query builder
közvetlen tömeges `update`/`insert` műveletei nem hívják a modell mentését;
ezek nem általános paraméterkezelési felületek.

**Meglévő duplikációkat nem javítunk automatikusan.** Egy aktív duplikált
sort mentés előtt inaktiválni vagy valóban eltérő kulcsra kell módosítani.
Az inaktiválás továbbra is megengedett. A NAS-on korábban azonosított
#60/#75 globális vámparaméterekhez ez a fejlesztés nem nyúl hozzá.

### Ellenőrzés és kiadás

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingParameterValidationTest.php tests/Feature/PricingConfigurationCopyTest.php tests/Feature/PricingMaterialScopeTest.php
```

Az utolsó célzott futás az engedélyezési tesztekkel együtt **59 tesztet,
377 ellenőrzést** teljesített. A kibővített admin/inline/publikálási futás
**73 teszt, 431 ellenőrzés**, sikeres. A 212 tesztes számítási regresszióban
193 teszt sikerült, 19 outlet-teszt már a korábbi modellkóddal is hiányos
fixture miatt állt le: nem volt explicit termék-projekt tagság. A tesztadat
tagságának pótlása után mind a **19 outlet-teszt, 120 ellenőrzés** sikeres.
A régi duplikációkat vizsgáló tesztek adatbázis-fixture-ben hozzák létre a
hibás állapotot; a normál mentés védelmét nem kapcsolják ki. Pint és
`git diff --check` sikeres. Élő böngészős QA, MySQL konkurenciateszt és
NAS-adatmódosítás nem történt.

Migráció, Composer install, npm build, manuális fájlfeltöltés és új jogosultság
**nem szükséges**. Telepítéskor a szokásos cache-törlés és -újraépítés kell.
A meglévő adatütközés kézi rendezése külön feladat. NAS-telepítés nem része
ennek a módosításnak; csak külön kérésre történik.

A pricing worktree-ből, ellenőrzés után:

```powershell
git add -- app/Exceptions/PricingParameterConflictException.php app/Models/PricingParameter.php app/Services/Pricing/PricingConfigurationCopyService.php app/Filament/Support/Concerns/GuardsPricingProjectWrites.php app/Filament/Resources/PricingParameters/Tables/PricingParametersTable.php app/Filament/Pages/PricingCostCalculations.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php tests/Feature/PricingParameterValidationTest.php tests/Feature/PricingConfigurationCopyTest.php tests/Feature/WholesaleCalculationTest.php tests/Feature/OutletCalculationTest.php docs/pricing-configuration-copy.md
git commit -m "Prevent duplicate active pricing parameters when saving and copying"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, csak külön engedélyezett és az éles ágba integrált kiadásnál:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Használat

Az **Árképzési paraméterek** és az **Árkerekítési szabályok** listáján:

1. Jelöld ki a másolandó sorokat; a szűrés és a kijelölés a már meglévő módon működik.
2. Csoportos műveletek → **Paraméterek másolása**, illetve **Kerekítési szabályok másolása**.
3. Válassz meglévő célárprojektet. Csak nem lezárt, megtekinthető és módosítható
   projekt választható, a kijelölt forrásprojektek nem.
4. Az ablak megmutatja a kijelölt, újként másolható és ütköző sorok számát.
   Ütközéskor kötelező külön dönteni: **meglévő sorok kihagyása**, vagy
   **felülírás a forrás adataival**. Nincs automatikus felülírási alapértelmezés.
5. A Másolás gomb végrehajtja a műveletet. A Mégse vagy az előnézet elkészítése
   nem ír az adatbázisba. Az eredmény jelzi az új, felülírt, kihagyott,
   változatlan és felülvizsgálatra jelölt ársorok számát.

A felülírás a meglévő célrekord azonosítóját megőrzi; azonos értékek esetén nem
mentjük újra feleslegesen. Kihagyáskor a meglévő célrekordok egyáltalán nem
változnak, de az ütközésmentes kijelölt sorok másolódnak. A források mindig
változatlanok, akár lezárt projektből is másolhatók megfelelő olvasási joggal.

## Mi számít ütközésnek?

Paraméter: azonos paramétertípus + szűkítés + az adott szűkítéshez tartozó ország/
főcsoport/anyagösszetétel/modell + árlista + pénznem. A szűkítésnél nem használt, korábbi
kitöltésből visszamaradt mezők nem alkotnak külön kulcsot; ezeket az új vagy
felülírt sorban nullázzuk. Az országkódot nagybetűsre normalizáljuk.

Kerekítési szabály: azonos ártípus + árlista + pénznem. A név, kerekítési mód,
egység, tizedesek és korrekció a másolandó tartalom, nem a kulcs része.

Az aktív/inaktív állapotot is másoljuk, de nem használjuk az ütközési kulcsban.
Az üres árlista vagy pénznem globális beállítást jelent, és nem azonos egy
konkrét árlistához/pénznemhez tartozó beállítással.

Ha a kijelölésben ugyanarra a kulcsra több forrássor van, a program nem dönt
önkényesen: kulcsonként egyet kell kijelölni. Több forrásprojektből egyidejűleg
is másolhatók különböző kulcsú sorok. Ha a célban ugyanarra a kulcsra több
rekord van, az érintett ütközések kihagyhatók; felülíráshoz előbb rendezni kell
a duplikációkat. Nincs automatikus törlés vagy sor-összevonás.

## Szezonok és árak

A modell-, főcsoport-, árlista- és pénznem-hivatkozások változatlanul mennek át.
**Nem keresünk automatikusan másik szezonbeli termékpárt.** Eltérő szezonnál a
modellszintű paraméter továbbra is az eredeti konkrét termékre mutat, ezért
az ilyen sort külön ellenőrizni, szükség szerint átállítani kell. Ezt a
felugró ablak is jelzi. A globális/főcsoportos/országszintű beállításokra ez
a termékazonosítóhoz kötött korlátozás nem vonatkozik.

Az árértékeket, jóváhagyásokat, kalkulációs snapshotokat és publikált
`price_list_items` adatokat nem másoljuk és nem írjuk felül. Nincs automatikus
újraszámítás vagy jóváhagyás. A számítást befolyásoló aktív beállítás-változás
konzervatívan a célprojekt összes meglévő, nem bekerülési ársorát
felülvizsgálatra jelöli. A meglévő outlet-modellesemények is lefutnak.
Csak kihagyott vagy teljesen változatlan sorok esetén nem történik új mentés.

## Jogosultság és biztonság

Nincs új jogosultságobjektum:

- A másolandó típus listázási és létrehozási joga kell, a konkrét forrássorhoz
  megtekintési joggal (`ViewAny`, `Create`, `View`).
- A forrásprojektek megtekinthetők kell legyenek. A célprojekthez a listázáson
  felül `View:PricingProject` és `Update:PricingProject` szükséges.
- Felülíráshoz a konkrét meglévő célparaméter/szabály `Update` joga is kell.
  Enélkül csak a kihagyás kínálható fel; a szerver közvetlen hívással sem enged
  jogosulatlan felülírást.
- A másolás tranzakcióban, determinisztikus sorrendű projekt- és sorzárolással
  fut. Előbb minden ütközést és jogosultságot ellenőrzünk, csak utána írunk.
- A megerősítés felhasználóhoz, forrásokhoz és célállapothoz kötött HMAC tokennel
  védett. Ha közben változott egy forrás vagy ütköző célrekord, illetve új
  ütközés keletkezett, új előnézet és döntés kell. Az **Előnézet frissítése**
  gomb ezt a kijelölés elvesztése nélkül megteszi.
- Ha a cél közben lezárult, a másolás leáll. Mentési hiba esetén a korábbi
  másolások és a kapcsolódó outlet-felülvizsgálati jelölések is visszagördülnek.

## Ellenőrzés

Célzott tesztek:

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingConfigurationCopyTest.php
```

A PHP/Livewire tesztek mindkét listát és a valódi előnézeti mezőfrissítést,
ütközésválasztást, felülírást, kihagyást, Mégsét, elavult/hamisított állapotot,
jogosultságokat, zárolási életciklust, pontosságot és tranzakciós visszagördítést
ellenőrzik. Böngészős vizuális ellenőrzés nem történt.

A kapcsolódó teljes árképzési regresszió eredménye: **357 teszt, 2940 ellenőrzés,
sikeres**. A két korábbról ismert, fix admin-resource darabszámot váró tesztet
kihagytuk; az elvárásaikat nem módosítottuk. A PHP-kódformázás és a Git
whitespace-ellenőrzés is sikeres.

## Telepítés – csak külön kérésre

**Későbbi kiegészítés:** az anyagösszetétel szerinti szűkítéssel és az explicit
termékkijelöléssel együtt kiadva már szükséges adatbázis-migráció és előzetes
ütközésellenőrzés. Ennek lépései: [pricing-project-products.md](pricing-project-products.md).
Az alábbi „nem szükséges migráció” az eredeti, önálló másolási fejlesztésre vonatkozik.

- Migráció: **nem**.
- Composer install: **nem**.
- npm build: **nem**.
- Manuális fájlfeltöltés vagy `.env`-módosítás: **nem**.
- Új lapjog kiosztása vagy Shield-generálás: **nem**; a meglévő jogokat használjuk.
- Másolás után szükség lehet a célprojekt árainak újraszámítására és jóváhagyására.

A `.worktrees/pricing-module` könyvtárból:

```bash
git add -- app/Filament/Resources/PricingParameters/Tables/PricingParametersTable.php app/Filament/Resources/PricingRoundingRules/Tables/PricingRoundingRulesTable.php app/Filament/Support/Actions/CopyPricingConfigurationAction.php app/Services/Pricing/PricingConfigurationCopyService.php tests/Feature/PricingConfigurationCopyTest.php docs/pricing-configuration-copy.md
git commit -m "Copy selected pricing parameters and rounding rules between projects"
git push origin feature/pricing-module
```

NAS:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, csak külön engedélyezett, éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
