# Árprojekt tételeinek törlése és árképzési áttekintő

## Tétel törlése

Az **Árprojektek → Szerkesztés → Árkalkulációs sorok** táblában:

- Egy sor törlése az adott **árprojekt + modell + szín** kombináció összes
  kalkulációját törli, minden pénznemben és árlistán: bekerülési érték,
  kisker, outlet, nagyker és disztribútori ár.
- A kijelöléses tömeges törlés ugyanezt végzi minden kijelölt tétellel.
  Egy tétel több ársorának kijelölése nem eredményez többszörös törlést.
- A megerősítés külön kimondja: **a már publikált árlisták változatlanok
  maradnak**. Sem a `price_list_items`, sem a publikálási snapshot nem módosul.
- Más projektek, más színek, a terméktörzs, beszerzési árak és paraméterek
  nem törlődnek. A továbbiakban végzett újraszámítás ezekből ismét létrehozhatja
  a kalkulációkat; a figyelmeztetés erre is kitér. A modell tartós kivételéhez
  az új **Árprojekt termékei → Kijelölt termékek kivétele** művelet használható;
  ez a termék-hozzárendelést is eltávolítja. Lásd: `pricing-project-products.md`.
- A különálló **Árkalkulációs sorok** menü meglévő törlése nem változik;
  az összes árat érintő törlés kifejezetten a projekt alatti kapcsolt listán van.

A teljes művelet tranzakcióban történik. A szolgáltatás zárolja és újraolvassa
az árprojektet, ellenőrzi a kijelölt sorok projekthez tartozását, majd minden
érintett ársor törlési jogosultságát **az első törlés előtt** ellenőrzi. A
modell-események és a lezárt projektet védő törlési ellenőrzések megmaradnak.
Hiba esetén minden törlés és a függőségek módosítása visszagördül.

Szükséges meglévő jogosultságok: a projekt módosítása és a kalkulációs sor
törlése; tömegesen ezen felül `DeleteAny:PricingCalculationRow`. A megtekintő
oldal kapcsolt listája csak olvasható. Lezárt projekt akkor sem törölhető,
ha a felhasználó böngészőjében még a régi, nyitott állapot látszik.

## Árképzés áttekintése

Új menü: **Árképzés → Árképzés áttekintése**.

Felül árprojektválasztó és archív kapcsoló van. A kiválasztás ugyanaz a
felhasználónként mentett projektkontextus, amelyet az árképzési listák használnak.
Több nyitott projekt esetén nincs automatikus, összes projektet egyesítő nézet.
Egyetlen elérhető projekt automatikusan kiválasztódik. A lépés megnyitásakor a
rendszer újra elmenti a kiválasztást, így másik böngészőfül projektváltása sem
viszi át rossz projektre a megnyitott oldalt.

Az áttekintő sorrendje:

1. Bekerülési értékek.
2. HUF kisker- és outletárak.
3. Devizás kisker- és outletárak, minden pénznem együtt.
4. HUF és EUR nagykerárak.
5. EUR disztribútori árak.
6. Teljes áráttekintés.
7. Hivatkozás az árprojekt adatlapjára: lezárás, publikálás és ERP árexport.

Lépésenként összes / jóváhagyott / függő darabszám és Megnyitás gomb jelenik meg.
Az outlet darabszám az összesítés **része**, nem hozzáadandó extra:
például „Függő: 200 (50 outlet)”. A tételszám a már mentett ársorok száma;
a hiányzó, még ki nem számított kombinációkat nem találjuk ki. A bekerülési
értéknek nincs külön jóváhagyása, ezért ott „Rendben” szerepel. A végső
áttekintés modellenként/színenként számol, az aktív termékek alap-bekerülési
soraihoz igazodva; bármely függő ár függővé teszi a tételt.

Függő egy ársor, ha `needsPricingAttention()` igaz: nem jóváhagyott, nem pozitív,
felülvizsgálandó vagy outletnél a minimális kedvezmény nem teljesül.
Ez állapotösszesítés, nem újrakalkulálás vagy publikálási előellenőrzés. A
lezárás továbbra is ellenőrzi a teljes számítás aktualitását és a forrásokat.

Az áttekintő nem tölt teljes táblázatokat, snapshotokat vagy forrásláncokat a
böngészőbe: csak a kiválasztott projekt számlálói jelennek meg. Kézi Frissítés
és percenkénti automatikus frissítés is van. Nincs ár- vagy jóváhagyás-módosítás.

## Ellenőrzés

### Lista- és számlálónavigációs kiegészítés (2026-10-20)

- Az Árprojektek listában többválasztós **Árprojekt** és **Szezon** szűrő van.
- Az Árkalkulációs sorok általános menüjében és a projekt kapcsolt listáján
  nincs alapértelmezett „Bekerülési érték” szűrés. Kézzel továbbra is választható
  az ártípus. A külön Bekerülési értékek munkalap továbbra is kizárólag költségsorokat mutat.
- Az Árprojekt termékei kapcsolt listán és a termékválasztó oldalon is
  **50 / 100 / 250 / 500 / összes** a lapméretválaszték, **100** az alapértelmezett.
- Mind a hat áttekintő blokkban a teljes számlálóterület kattintható,
  billentyűzettel is elérhető. Összes tétel: nincs állapotszűrés;
  Jóváhagyott/Rendben: elfogadható ársorok; Függő: figyelmet igénylő ársorok.
- A céloldalon az **Áttekintő szerinti állapot** szűrő látható, módosítható
  és törölhető. A kattintás az árprojektet és az archív módot is megőrzi,
  másik böngészőfül projektváltásától függetlenül; a meglévő oldaljogok érvényesek.
  Az explicit URL-szűrők a pénznem/outlet beállítások mount alatti visszatöltése
  után kerülnek alkalmazásra. A számlálólink nem örököl korábbi, nem kapcsolódó szűrőket.
- A pénznemválasztós munkalapokon a korábban kiválasztott pénznem marad;
  a disztribútori lapon a kiválasztott disztribútori árlista. Az áttekintő
  darabszámai több pénznemet/árlistát is összesíthetnek.
- A kisker és az outlet egy megjelenített sorban szerepel. Az állapotszűrő
  megtalálja a sort, ha bármelyik ott megjelenített ár megfelel a kiválasztott
  állapotnak. Emiatt egy jóváhagyott kisker + függő outlet terméksor mindkét
  nézetben szerepelhet. A végső áttekintésben viszont csak akkor Rendben egy
  teljes modell/szín, ha egyik ára sem függő. A disztribútori szűrés a
  disztribútori célsort, nem a nagyker forrás státuszát vizsgálja.

Ehhez a kiegészítéshez **nem kell migráció, composer install, npm build,
kézi fájlfeltöltés, új jogosultság vagy .env-módosítás**. A CSS közvetlenül
a public könyvtárból töltődik, verziózott hivatkozással. Telepítéskor
`optimize:clear` és `optimize` szükséges.

Ellenőrzés: 61 tesztes lista/grid/teljesítmény regresszió (701 ellenőrzés),
majd 35 tesztes kibővített navigációs/projektkontextus kör (404 ellenőrzés)
sikeres. A két kör részben átfed: összesen 78 különböző tesztet fednek le.
Pint és `git diff --check` sikeres. PHP/Livewire és renderelt HTML ellenőrzés;
élő böngészős vizuális próba és NAS-telepítés ebben a fejlesztési körben nem történt.

A külön kérhető kihelyezés Git/NAS parancsai a pricing worktree-ben:

```powershell
git add -- app/Filament/Pages/PricingWorkflow.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php app/Filament/Resources/PricingProjects/Pages/SelectPricingProjectProducts.php app/Filament/Resources/PricingProjects/RelationManagers/ProductsRelationManager.php app/Filament/Resources/PricingProjects/Tables/PricingProjectsTable.php app/Filament/Support/AdminResourceTable.php app/Filament/Support/Concerns/HasPricingTablePreferences.php app/Filament/Support/Concerns/HasPricingWorkflowNavigation.php app/Filament/Support/PricingWorkflowFilter.php app/Services/Pricing/PricingAttentionQuery.php public/css/admin-table-scroll.css resources/views/filament/pages/pricing-workflow.blade.php tests/Feature/PricingWorkflowTest.php docs/pricing-workflow-and-item-deletion.md
git commit -m "Add pricing workflow state navigation and improve project list filters"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles, jóváhagyott és az éles ágba integrált kiadás esetén:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

### Korábbi alapfunkciók ellenőrzése

Kapcsolódó teljes regresszió: **328 teszt, 2773 ellenőrzés, sikeres**.
A két korábbról ismert, fix admin-resource darabszámot váró teszt kimaradt;
azok elvárásait nem módosítottuk. A formázás és a whitespace-ellenőrzés is sikeres.

A tesztek kiterjednek az egyedi és tömeges törlésre, a több árlistára és
pénznemre kiterjesztett kijelölésre, a más projektek/színek megőrzésére,
publikált árak és snapshotok változatlanságára, törlési hiba utáni teljes
visszagördítésre, jogosultságokra és a közben lezárt projekt védelmére.
Az áttekintőn projekt- és archívváltás, outlet-minimum, minden számláló,
több böngészőfül közötti navigáció és a kis Livewire-válasz is ellenőrzött.
Ez PHP/Livewire és renderelt HTML ellenőrzés, nem böngészős vizuális teszt.

Célzott futtatás a worktree-ből:

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingWorkflowTest.php tests/Feature/PricingProjectPublicationTest.php
```

## Jogosultság és telepítés

Új lapjog: **`View:PricingWorkflow`**. A migráció létrehozza, de nem rendeli
hozzá automatikusan semmilyen szerepkörhöz. A super admin látja az új menüt.
Más szerepköröknél a Szerepkörök oldalon engedélyezni kell; emellett kell
`ViewAny:PricingCalculationRow`, `ViewAny:PricingProject` és `View:PricingProject`.
A céloldalak saját lapjogosultságai változatlanok; jogosultság nélkül a
Megnyitás gomb és a közvetlen Livewire-hívás sem használható.

- Adatbázis-migráció: **igen**, csak az új lapjog létrehozása, nincs új séma.
- Composer install: nem.
- npm build: nem; a CSS közvetlenül a `public/css` alatt van.
- Kézi feltöltés / `.env` módosítás: nem.
- További teendő: az új lapjog hozzárendelése a nem-super-admin szerepkörökhöz.
- `shield:generate` nem szükséges; a meglévő policykat nem generáljuk felül.

Git a `.worktrees/pricing-module` könyvtárból (a korábbi, még nem commitolt
kompakt oszlopválasztókhoz külön a kapcsolódó leírás tartalmazza a fájllistát):

```bash
git add -- app/Filament/Pages/PricingWorkflow.php app/Filament/Resources/PricingProjects/RelationManagers/CalculationRowsRelationManager.php app/Services/Pricing/PricingProjectItemDeletionService.php app/Services/Pricing/PricingWorkflowSummary.php database/migrations/2026_10_19_120000_add_pricing_workflow_page_permission.php resources/views/filament/pages/pricing-workflow.blade.php public/css/admin-table-scroll.css tests/Feature/PricingProjectPublicationTest.php tests/Feature/PricingWorkflowTest.php docs/pricing-workflow-and-item-deletion.md
git commit -m "Add project pricing workflow and atomic cross-list item deletion"
git push origin feature/pricing-module
```

NAS, külön kért telepítéskor:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel: csak külön jóváhagyott, éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
