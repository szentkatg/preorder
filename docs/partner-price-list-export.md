# Partner árlista készítése

Az admin felületen: **Árképzés → Partner árlista készítése**.

## Használat

1. Szezon és aktív nettó nagyker/disztribútori árlista kiválasztása.
2. Opcionálisan azonos pénznemű bruttó kisker árlista. Alapból a nettó
   árlistához kapcsolt kisker árlista kerül ide, ha aktív és megfelelő típusú.
3. Magyar vagy angol nyelv és Excel/PDF formátum kiválasztása.
4. Márkák, főcsoportok és katalóguscsoportok szűrése. Egy mezőn belül több
   érték választható; üresen az összeset jelenti. A különböző szűrők együtt
   érvényesülnek. A katalóguscsoport-választó a többi termékszűrőt is követi.
5. **Terméklista betöltése**: minden találat alapból kijelölt, az összes
   oldalon, nem csak a megjelenített 100 sor. A soronkénti jelölők,
   **Mindent kijelöl** és **Kijelölés visszavonása** módosítják a kijelölést.
6. **Árlista készítése** letölti a kiválasztott termékeket. A táblázatban
   további keresés/rendezés használható; a fájl a jelenlegi szűrésen belül
   kijelölt termékekből, katalógussorrendben készül. Az űrlap feltételeinek
   módosítása törli az előnézetet/kijelölést; ilyenkor újra be kell tölteni.

Csak aktív termékeket listázunk a választott szezonból. Korábbi szezon is
választható; az árprojektek állapota ezt a mentett árlistákból készülő
exportot nem korlátozza. Egy fájl legfeljebb 10 000 terméket tartalmazhat.

## Árforrás és fordítás

- Kizárólag a **price_list_items** mentett tételeit használjuk, az árlista,
  termék és **tételszintű season_id** egyezésével. A kalkulációs sorokat nem
  olvassuk, és a funkció nem módosít árakat/adatbázist.
- A retail árlisták `net_price` mezője a partnerportál meglévő működésének
  megfelelően a bruttó kisker árat tárolja. Nincs ÁFA- vagy árfolyamszámítás.
- Hiányzó ár/adat: `-`, nem nulla. A valódi nulla numerikus nulla marad.
  A hiányos termékek láthatók az előnézetben, és igény szerint kihagyhatók.
- Fejlécek: `lang/hu/price_list.php`, `lang/en/price_list.php`.
- Márka, főcsoport, rendelőlaptípus és szezon neve: `translations`, a
  meglévő névmezőkre való visszaeséssel. Terméknév és katalóguscsoport:
  `name_hu/name_en`, illetve `catalog_group_name_hu/catalog_group_name_en`.
  Hiányzó megfelelő nyelvű névnél a másik nyelvű név látszik.
- Az exportnyelv az admin felület locale-ját nem változtatja meg.

## Fájlok

Excelben márkánként/rendelőlaptípusonként külön munkalap, főcsoportos
elválasztók, ismétlődő nyomtatási fejléc, fagyasztott azonosítóoszlopok,
A4 fekvő nyomtatás. Az árak számok, rendszer szerinti tizedeselválasztóval;
a dátumok valódi Excel-dátumok. Szövegmezőből nem keletkezhet Excel-képlet.

PDF-ben márkánként/rendelőlaptípusonként új szakasz, ismétlődő táblázatfejléc,
A4 fekvő formátum és oldalszámozás. Unicode betűkészlet; magyar ékezetekkel.
Mindkét formátum PHP-val készül, szerveroldali Node.js nélkül.

## Jogosultságok

Super_admin automatikusan hozzáfér. Más szerepköröknek:

- Oldalak: **View:PartnerPriceListExport**.
- Termék: **ViewAny:Product**, **View:Product**.
- Árlista: **ViewAny:PriceList**, **View:PriceList**.
- Árlistatétel: **ViewAny:PriceListItem**, **View:PriceListItem**.

Írási jogosultság nem szükséges. A letöltés újraellenőrzi a jogosultságokat,
az árlisták aktív állapotát és a kijelölt termékek szűrését. A szerepkör
mentése létrehozza a kijelölt új oldaljogot; `shield:generate --all` nem kell,
és meglévő policyt nem generálunk felül.

## Ellenőrzés

```powershell
php -d memory_limit=256M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PartnerPriceListExportTest.php tests/Feature/PriceListSeasonTest.php tests/Feature/Admin/AdminTableExportTest.php
```

A tesztek a szűrést, lapozáson átívelő kijelölést, jogosultságokat,
pénznemegyezést, fordítást, numerikus árakat/dátumokat, hiányzó árakat és a
valódi Excel/PDF-letöltést ellenőrzik. Magyar/angol, többoldalas és több
munkalapos tesztkimenetek vizuálisan is ellenőrizve.

## Telepítés – csak külön kérésre

**Composer install szükséges** (`dompdf/dompdf`, lockfájlban rögzítve).
**Nincs migráció, npm build, kézi assetfeltöltés vagy .env módosítás.**
A Laravel szokásos írható `storage` könyvtára szükséges a PDF ideiglenes
fájljaihoz; ez nem igényel külön konfigurációt.

Git a pricing worktree-ben (a `.tmp` ellenőrző fájlokat nem commitoljuk):

```powershell
git add -- composer.json composer.lock app/Exports/PartnerPriceListExport.php app/Exports/PartnerPriceListSheet.php app/Filament/Pages/PartnerPriceListExport.php app/Services/PartnerPriceListService.php app/Services/PartnerPriceListPdf.php lang/hu/price_list.php lang/en/price_list.php resources/views/exports/partner-price-list.blade.php resources/views/filament/pages/partner-price-list-export.blade.php tests/Feature/PartnerPriceListExportTest.php docs/partner-price-list-export.md
git commit -m "Add multilingual partner price list Excel and PDF export"
git push origin feature/pricing-module
powershell.exe -NoProfile -File infrastructure/scripts/deploy-nas.ps1
```

A NAS telepítő észleli a Composer-változást és telepíti a függőségeket,
törli/újraépíti a cache-t. Éles/WePanel kihelyezés nem része ennek a lépésnek;
ott csak az éles ágra jóváhagyott kód kerülhet ki. A jóváhagyott éles kiadás
parancsai a projekt könyvtárában, a Composer is ugyanazzal a PHP-val:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php /a/composer/tenyleges/eleresi/utja install --no-dev --optimize-autoloader
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

A Composer tényleges útját a tárhelyen kell ellenőrizni (`command -v composer`);
a fenti helykitöltőt nem szabad változatlanul futtatni. Migráció ehhez a
fejlesztéshez nincs. Nem super_admin kollégáknál a fenti oldal- és olvasási
jogokat a szerepkörben engedélyezni kell.
