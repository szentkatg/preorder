# Szezonfüggetlen árlistatörzs

Az árlista tartós árkategória: például `Retail_HU`. Kódja továbbra is
globálisan egyedi. A `price_lists.season_id` mezőt kivezetjük az adatbázisból,
modellből, űrlapból, listából, szűrésből és Excel-exportból.

Az ár érvényességi szezonja a `price_list_items.season_id`.
Az egyediség változatlan: **árlista + szezon + termék**.
Ugyanaz a termék ugyanabban az árlistában több szezonra eltérő árat kaphat.
Az ár szezonjának nem kell megegyeznie a `products.season_id` mezővel.
A tételek szezonja és ára a migráció során nem változik.

## Működés

- A partnercímek árlista-hozzárendelése és a nagyker–kisker kapcsolatok megmaradnak.
- Megosztáskor minden aktív árlista választható; nincs fejlécszezon szerinti szűrés.
- A rendelőlap továbbra is a rendelés szezonjának megfelelő tételárat keresi.
  Hiányzó szezonár esetén a korábbi viselkedés marad: nincs másik szezonból fallback.
- Az árlista-import formátuma nem változik: a tételeknél kell a `season_code`.
- A HUF-kalkuláció bármely aktív HUF kisker árlistára futtatható.
  A kalkuláció szezonját az árprojekt határozza meg, az eredmények projekt szerint elkülönülnek.
- Duplikált árlistakód létrehozásakor vagy szerkesztésekor mezőszintű hiba jelenik meg.
  Szerkesztéskor a saját kód megtartható.

A termékek szezonbesorolása, rendelőlapi termékválasztása és a bekerülési/HUF
kalkuláció termékszezon-szűrése nem változik ebben a lépésben. Egy korábbi szezon
termékének új szezonbeli kalkulálhatósága/rendelhetősége külön fejlesztés.

## Telepítés előtti mentés és ellenőrzés

Migráció: `2026_10_12_150000_remove_season_id_from_price_lists_table`.
**Telepítés előtt adatbázismentés szükséges.** Az elavult fejlécszezonok
törlődnek; a migration `down()` csak a nullable mezőt és idegen kulcsot
hozza vissza, a korábbi értékeket nem. Teljes visszaállításhoz a mentés és
a korábbi kódverzió együttes visszaállítása szükséges.

A migráció előtt a fejléc és a tételek eltérései ezzel ellenőrizhetők (MySQL):

```sql
SELECT pl.id, pl.code, pl.season_id AS header_season_id,
       pli.season_id AS item_season_id, COUNT(*) AS item_count
FROM price_lists pl
JOIN price_list_items pli ON pli.price_list_id = pl.id
WHERE pl.season_id IS NOT NULL
  AND (pli.season_id IS NULL OR pli.season_id <> pl.season_id)
GROUP BY pl.id, pl.code, pl.season_id, pli.season_id;
```

Az eltérő tételeket nem javítjuk automatikusan a fejléc szerint, mert ezek
lehetnek szándékos, más szezonra rögzített árak.

## Tesztelés

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PriceListSeasonTest.php tests/Feature/HufRetailCalculationTest.php tests/Feature/DatabaseMigrationTest.php
```

Eredmény: 25 teszt, 171 assertion sikeres; a migráció oda-vissza és
a két szezon eltérő árának importálása/lekérése is tesztelt.
A teljes csomag ideiglenes teszt APP_KEY mellett 66 tesztből kettőnél
áll meg: az AdminAuthorizationTest rögzített 30-as resource-darabszámot vár,
miközben 32, illetve 31 elemet talál. A többi 64 teszt sikeres.
Ezeket a módosítástól független darabszám-elvárásokat most nem írjuk át.

## Git és NAS

A `feature/pricing-module` worktree-ben, csak e módosítás fájljaival:

```powershell
git add -- app/Models/PriceList.php app/Filament/Resources/PriceLists/Schemas/PriceListForm.php app/Filament/Resources/PriceLists/Tables/PriceListsTable.php app/Livewire/Partner/CatalogGroupOrder.php app/Services/Pricing/HufRetailCalculationService.php app/Services/Pricing/HufRetailCalculator.php app/Filament/Support/Actions/CalculateHufRetailAction.php database/migrations/2026_10_12_150000_remove_season_id_from_price_lists_table.php tests/Feature/PriceListSeasonTest.php tests/Feature/HufRetailCalculationTest.php tests/Feature/DatabaseMigrationTest.php docs/price-list-seasons.md docs/huf-retail-pricing.md
git commit -m "Make price lists season independent"
git push origin feature/pricing-module
# Csak adatbázismentés után:
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Az univerzális NAS script nem készít adatbázismentést: azt külön, előzetesen
kell elkészíteni. Composer install, npm build és kézi fájlfeltöltés nem kell.
Shield-generálás és .env módosítás nem szükséges.

## cPanel

Csak külön jóváhagyott élesítéskor, az éles ágba integrálás és mentés után,
a szerver projektkönyvtárában:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Nincs Composer-/Vite-változás és manuálisan feltöltendő asset.
