# Teljes árprojekt-számítás és közvetlen termékár-kezelés

## Használat

Az **Árprojektek** listájában, valamint a projekt megtekintő- és szerkesztőoldalán
a **Minden ár kiszámítása** művelet a projekt szezonjának aktív modelljeit dolgozza fel.
Csak nyitott projektnél, projektmódosítási és kalkulációs létrehozási/módosítási,
illetve kalkulációs listázási jogosultsággal érhető el.

Modellenként a teljes függőségi sorrendet követi:

1. Bekerülési érték: legolcsóbb érvényes aktív beszerzési lehetőség.
2. HUF kisker árlisták.
3. Devizás kisker árlisták, a kiválasztott HUF forrásból.
4. Nagyker árlisták, saját kapcsolt, azonos pénznemű kisker forrásukból.
5. Disztribútori árlisták a kapcsolt nagykerből, outlet árak a kapcsolt kiskerből.

Az aktív árlisták közül a modellre alkalmazható paraméterrel vagy meglévő
kalkulációs sorral rendelkezők vesznek részt. Paraméterrel engedélyezett, de
hiányzó kerekítésű árlistát nem hagyunk ki csendben: a modell hibás lesz.
Egyértelmű HUF forrás esetén nincs árlistaválasztás. Több forrásnál a megerősítő
ablakban devizás célárlistánként kell HUF forrást választani.

**Ez nem jóváhagyás és nem publikálás.** A kézi árak és a korábban jóváhagyott
árak megtartása, státusza, jóváhagyási előzménye megmarad. A függő számítások
a megtartott/manuális végleges forrásárat használják, nem egy még el nem fogadott
új kalkulált javaslatot. Megváltozott bemenetnél a felülvizsgálati jelzés megmarad,
és a függő árakra is kiterjed. Friss számítás önmagában nem jelent jóváhagyást.
A bekerülési értéknek nincs önálló jóváhagyása; sikeres számítása után aktuális.

Egy modell minden lépése egy tranzakció: hiba esetén a teljes modell számítása
visszagördül. Meglévő árai változatlanok, de felülvizsgálatra jelöltek lesznek.
A többi modell feldolgozása folytatódik. A befejező üzenet modell-/ársorszámot,
hibás modellek számát, felülvizsgálandó sorok számát és futásazonosítót ad.
A hibák ugyanazzal a futásazonosítóval az **Árkalkulációs hibák** között,
**Teljes árprojekt** ártípussal találhatók; erre külön szűrni is lehet.
A teljes futás is tranzakciós: váratlan technikai hiba esetén nem marad részleges
projektfrissítés. A futás nem írja az árlistatételeket és nem változtat paramétert.

## Modell közvetlen megnyitása

A bekerülési, HUF/devizás kisker-, nagyker-, disztribútori és teljes áttekintő
listák **Műveletek → Termékenkénti árkezelés** pontja a megfelelő projektet és
modellt nyitja meg a szerkesztőablakban. Az általános Árkalkulációs sorok listán
is elérhető ugyanez a művelet. A bekerülési lista új menüjében ez az egy művelet van.
A dupla kattintásos bevitel és részletezés változatlan marad.

A céloldal önálló jogosultsága (`View:PricingProductWorkspace`), projekt- és
kalkulációs módosítási jogok szükségesek. A közvetlen URL sem kerüli meg ezeket,
a projekt/szezon/modell egyezését vagy a lezárás védelmét. A felhasználó korábbi
projektválasztása nem téríti el a hivatkozás célját.

## Termékszerkesztő elrendezése

Balra táblázatos sorokban vannak a beszerzési árak, modellparaméterek,
HUF forrásárlista-választók és a csak olvasható szezonárfolyamok. Jobbra
az árak és a végleges árakból számolt tényleges szorzók láthatók:

- bekerülési szorzó: végleges ár × mentett kisker árfolyam / HUF bekerülési érték;
- kereskedelmi kisker szorzó: azonos pénznemű bruttó kisker / adott ár
  (nagyker és disztribútor esetén nettó, outlet esetén bruttó ár);
- disztribútori nagyker szorzó: azonos pénznemű nettó nagyker / nettó disztribútori ár.

Az EUR/devizás árfolyamot a tényleges kisker forrásláncból vesszük. A devizás
kisker sorra nem mutatunk HUF/deviza hányadost kereskedelmi szorzóként. Hiányzó
bemenet vagy nem értelmezett szorzó helyén gondolatjel látható, nem nulla.
A szorzók két tizedesre jelennek meg, és az előnézet minden sikeres újraszámításakor
frissülnek. A kiválasztott bekerülési lehetőség részletei külön kompakt táblában
maradnak visszakövethetők. Keskeny képernyőn a két fő táblázat egymás alá kerül.

## Ellenőrzés

300 kapcsolódó regressziós teszt, 2313 assertion sikeres. A két korábbról ismert,
fix admin-resource darabszámot váró tesztet kihagytuk, azok elvárásait nem módosítottuk.
A műveletmenük elemszámát ellenőrző teszteket viszont az új navigációs ponthoz igazítottuk.
Külön ellenőrzött a teljes sorrend, a kézi/jóváhagyott árak megőrzése, a felülvizsgálat
továbbadása, az ismételt futás, a hibás modell visszagördítése és javítás utáni újrafuttatása,
a hiányzó kerekítés, a többértelmű forrás kiválasztása, a jogosultságok és a közvetlen URL
projekt-/modellszűkítése, valamint a Livewire-modal renderelése és a szorzók számszerű értéke.
Ez automatizált PHP/Livewire-ellenőrzés; tényleges böngészős vizuális/JavaScript-interakciós
ellenőrzés ebben a munkamenetben nem történt. A helyi Node-futtatókörnyezet nem volt elérhető.

## Telepítés

Nincs új migráció, Composer-függőség, npm build, `.env`-változás, új jogosultság,
kézi assetfeltöltés vagy kötelező üzemeltetői beavatkozás. A CSS Gitből frissül,
fájlidőbélyeges cache-verzióval. A meglévő jogosultságokat nem osztjuk ki automatikusan.

Git a `.worktrees/pricing-module` könyvtárból, ellenőrzés után:

```bash
git add -- app/Filament/Pages/PricingProductOverview.php app/Filament/Pages/PricingProductWorkspace.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php app/Filament/Resources/PricingProjects/Pages/EditPricingProject.php app/Filament/Resources/PricingProjects/Pages/ViewPricingProject.php app/Filament/Resources/PricingProjects/Tables/PricingProjectsTable.php app/Filament/Support/Actions/CalculateAllProjectPricesAction.php app/Filament/Support/Concerns/HasPricingGridInteractions.php app/Filament/Support/ProductPricingNavigation.php app/Services/Pricing/PricingProjectCalculationService.php app/Services/Pricing/ProductPricingService.php public/css/admin-table-scroll.css resources/views/filament/pages/pricing-cost-calculations.blade.php resources/views/filament/pricing/cost-grid-interactions.blade.php resources/views/filament/pricing/product-workspace-editor.blade.php resources/views/filament/pricing/row-operations.blade.php tests/Feature/PricingCostDoubleClickTest.php tests/Feature/PricingGridInteractionsTest.php tests/Feature/ProductPricingWorkspaceTest.php docs/project-wide-price-calculation.md
git add -- tests/Feature/DistributorCalculationTest.php tests/Feature/WholesaleCalculationTest.php tests/Feature/ForeignRetailCalculationTest.php
git add -- app/Models/PricingCalculationError.php app/Filament/Resources/PricingCalculationErrors/Tables/PricingCalculationErrorsTable.php tests/Feature/PricingCalculationErrorsTest.php
git commit -m "Add dependency-ordered project price calculation and direct model workspace links"
git push origin feature/pricing-module
```

NAS, csak külön kért telepítéskor:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel: csak külön jóváhagyott és az éles ágba integrált release után,
az éles projekt könyvtárából, nem a fejlesztői ág közvetlen behúzásával:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
