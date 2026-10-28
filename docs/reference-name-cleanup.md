# Törzsnevek fordítása és régi mezők kivezetése

## Adatmodell

| Tábla | Alapnév | Fordítás |
| --- | --- | --- |
| price_lists | name | price_list / code / name |
| order_sheet_types | name | order_sheet_type / code / name |
| currencies | name | currency / code / name |
| languages | name | language / code / name |
| item_main_groups | name | item_main_group / code / name |
| size_ranges | name_hu (közös név) | Nincs; name_en kivezetve |

Az első öt tábla `name_hu` és `name_en` oszlopai megszűnnek. A fordítás a meglévő
`translations` táblában marad. Az árlista az alkalmazás tényleges
`App\Services\Translation\TranslationRegistry` nyilvántartásába került, a modell
`HasTranslations` traitet használ. Az árlista adatlapján a **Név** az alapérték;
a fordítások az admin **Fordítások → Árlista**, árlistakód, **Név**, nyelv alapján
szerkeszthetők. Hiányzó fordításnál a `name` alapérték látszik.

A termék- és színnevek továbbra is közös, nem fordítandó értékek:
`shared-product-names.md`. A méretsor közös neve is marad `name_hu` néven.

## Működés

- Az árlistanév a partner megosztási választójában és megosztáslistájában a partner
  aktuális nyelvén, a két rendelőlap-Excel exportban az export nyelvén jelenik meg.
- Az admin árlista-szerkesztő, lista és választók a kanonikus névre épülnek.
  A partnerárlista-fájl árlistakódos lábléce változatlan.
- A már átállított törzsek régi mezőhivatkozásai is átvezetve: főcsoportos
  oszlopok, választók, szűrők, kalkulációs pillanatképek, nyelvválasztó és fordítási
  alapérték. A rendelőlap-import kódot, alapnevet és mentett fordítást fogad el,
  megszüntetett oszlopot nem kérdez le.
- A méretsorok listájából, adatlapjáról és másolásából eltűnt az angol név.
  Kódjuk, méreteik, sorrendjük és `matrix_group` értékük megmarad.
- Kódok, azonosítók, jogok, árak és rendelési tételek nem változnak.

Az árlistaimport új fejléce `name`; a régi `name_hu` továbbra is elfogadott.
A régi `name_hu` / `name_en` értékek hiányzó HU/EN fordítást hozhatnak létre.
Meglévő fordítást nem írunk felül: eltérő érték sorhibát okoz, az árlista kimarad.
Fordításmódosítás a Fordítások menüben végezhető. Ha egy különböző nyelvi érték
megőrzéséhez hiányzik a nyelv a törzsből, hibát jelzünk. Az alapnévvel egyező érték
fordítás nélkül is megmarad az alapnévben.

## Migráció és visszaállítás

`2026_10_28_140000_finish_reference_name_translations.php`:

1. A régi nem üres értékekhez szükséges HU/EN nyelvazonosítókat ellenőrzi.
   Hiányzó vagy kétértelmű nyelvnél oszloptörlés előtt megáll.
2. Minden érintett régi értéket, inaktív rekordot is, a `legacy_name_archives`
   táblába archivál forrástábla, azonosító és kód szerint. Ez csak rollback-adat,
   nem új fordítási rendszer és nem jelenik meg az alkalmazásban.
3. Csak üres alapnevet pótol. A hiányzó fordításokat `insertOrIgnore` módszerrel
   viszi át; a meglévő fordításokat és időbélyegeiket nem írja felül. A NAS-on javított
   `Magyar Forint` / `Hungarian Forint` és nagybetűs nyelvnevek is megmaradnak.
4. Csak az archiválás és átvezetés után törli a régi oszlopokat.

Rollback újra létrehozza az oszlopokat és visszateszi az archivált eredeti értékeket,
a különböző angol méretsorneveket is. A későbbi fordításokat nem törli. Utólag
létrehozott rekordok alapneve a visszaállított HU mezőbe kerülhet. Pontos teljes
korábbi állapothoz a kihelyezés előtti adatbázismentés szükséges.
A sikeres rollback az archív táblát eltávolítja. MySQL DDL nem teljesen atomi,
infrastruktúrahiba esetén a mentés és migrációs állapot alapján kell helyreállítani.

## Ellenőrzés

Csak elkülönített tesztadatbázisban:

```powershell
php -d extension=pdo_sqlite -d memory_limit=1024M infrastructure/scripts/test-worktree.php tests/Feature/ReferenceNameCleanupTest.php tests/Feature/Partner/PartnerSeasonVisibilityTest.php tests/Feature/PriceListSeasonTest.php tests/Feature/DistributorCalculationTest.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/DatabaseMigrationTest.php
```

Tesztelt: séma, nyelv-preflight, meglévő fordítások megőrzése, hiányzó árlista-
fordítás pótlása, inaktív méretsor archiválása és rollback, új/régi import,
űrlapok, angol árlistanév a megosztásban és mindkét rendelőlap-exportban.
Két korábbról ismert admin-erőforrás-darabszám teszt elavult (30-at vár a jelenlegi
32/31 helyett); nem ennek a módosításnak a hibája. Az Excel-dátumképlet tesztadata
explicit képlettípust kap, hogy korábbi exportok globális értékkötője ne szövegesítse.

Futási eredmények: a két ismert darabszámtesztet kihagyó teljes futás 704 esetet
ellenőrzött, ebből 703 sikeres volt, egy a fenti, közben javított képlet-fixture miatt
hibázott. A javítás után az érintett import/export/partner csomag 55/55 sikeres,
az aktuális közvetlen névtakarítási csomag 8/8 sikeres. A javított fixture-rel a teljes
704-es futást nem ismételtük meg; nem állítjuk, hogy egyben újra lefutott.
Közvetlenül a NAS-kihelyezés előtt a migrációkat, fordításokat, importokat,
partnerárlistát, partnerfelületet és disztribútori működést lefedő újabb csomag
130/130 tesztje sikeres (842 assertion).

## Kihelyezés – csak külön kérésre

**Migráció, előzetes adatbázismentés és rövid maintenance szükséges.
Composer install, npm build, kézi feltöltés és .env-módosítás nem szükséges.**
A munkafában a korábbi kompakt partnerárlista-UI és közös terméknevek módosítása is
benne van. Commit előtt a teljes diff ellenőrzendő. Az éles adatbázist nem szabad
a NAS adatbázisával helyettesíteni.

Git a pricing worktree-ben (`git add -u` nem veszi fel a `.tmp/` és `output/` fájlokat):

```powershell
git diff --stat
git add -u
git add -- database/migrations/2026_10_28_130000_drop_redundant_product_and_color_english_names.php database/migrations/2026_10_28_140000_finish_reference_name_translations.php tests/Feature/SharedProductNamesMigrationTest.php tests/Feature/ReferenceNameCleanupTest.php docs/shared-product-names.md docs/reference-name-cleanup.md
git commit -m "Consolidate master names and translate price lists centrally"
git push origin feature/pricing-module
```

NAS: mentés és maintenance után a meglévő `infrastructure/scripts/deploy-nas.ps1`
futtatható. Csak sikeres migráció és ellenőrzés után szabad visszakapcsolni.

WePanel/korábbi cPanel: csak külön jóváhagyott éles ágba integrálás után,
kész adatbázismentéssel, a projekt könyvtárában:

```bash
/opt/alt/php83/usr/bin/php artisan down --retry=30
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize
# Csak sikeres ellenőrzés után:
/opt/alt/php83/usr/bin/php artisan up
```

Hibánál ne induljon el automatikusan az alkalmazás. Régi kódhoz előbb a hozzá
tartozó sémát is vissza kell állítani. Hosszú életű workerek újraindítása a
meglévő üzemeltetési eljárás szerint szükséges lehet.
