# Partneroldali rendeléskezelés és címenkénti rendeléstípusok

## Felület

A rendelésválasztó világoskék, keretezett paneljén lehet címet, márkát,
rendelőlapot és új rendelés adatait választani. Ez a rész vizuálisan elkülönül
az alatta látható rendeléslistától. A szezon továbbra is a munkamenetben választott.

A rendelésválasztó aljáról kikerült a kiválasztott rendelés katalóguscsoportos
összesítése. A rendelőlap navigációja sorrendben:

1. **Vissza a rendelésekhez** – rendelésválasztó.
2. **Összesített adatok** – az adott rendelés külön összesítőoldala.
3. Előző katalóguscsoport neve, ha van.
4. Következő katalóguscsoport neve, ha van.

Az összesítőoldal (`/partner/orders/{order}/overview`) tartalmazza a korábbi
csoportonkénti mennyiségeket, nagyker-/kiskerértékeket és végösszegeket,
a csoport megnyitását, a rendelés beküldését és a képviselői visszanyitást.
Csak a kiválasztott szezonban és engedélyezett címre szóló rendelés nyitható meg;
a hozzáférés visszavonását a további kéréseken is ellenőrizzük.

A **Partner rendelés lefedettség** és az ERP-export gomb csak aktív területi
képviselőnél jelenik meg. A visszanyitás a szerveren is képviselői művelet.
A megosztott, belépés nélküli rendelőlap nem ad átjárást a belső rendeléslistához
vagy összesítőoldalhoz; ott csak a csoportnavigáció marad.

## Excel-import a rendelésválasztóról és hiányzó hivatkozás pótlása

A rendeléslista műveleteinél a **Rendelés importálása** gomb külön ablakban nyitja
meg a többfájlos importot. Nem a rendeléslista kijelölése alapján importál: a fájl
fejléce (C3 címkód, C5 szezon, C6 márka, C7 rendelőlap, F1 hivatkozás) határozza
meg a célrendelést. Csak a kiválasztott szezon már létező, még nem beküldött és
a bejelentkezett felhasználó számára elérhető rendelése módosítható. A
rendelőlapról indított import továbbra is az ott megnyitott partnerre korlátozott.

Az **Importálás előnézete** megjelenésekor a felület az eredményhez görget,
a külön importablakban annak belső görgetésével. Az előnézet nem ment adatot.
Ha F1 üres, az adott fájl sorában hivatkozási szám írható be. Több hiányos
fájlhoz külön mező tartozik. A mező elhagyásakor csak azt a fájlt ellenőrizzük
újra, és frissülnek a várható változások. Érvényes találatnál a sor kijelölhető,
alapból kijelölt; ismeretlen vagy nem engedélyezett rendelés nem importálható.

A pótlás nem módosítja az Excel-fájlt és nem igényel új feltöltést. A már
kitöltött F1 nem írható felül így. A tényleges mentés a **Kijelöltek importálása**
gombbal történik, újabb jogosultság- és státuszellenőrzéssel. Új fájlfeltöltés,
az importablak bezárása vagy az import befejezése törli az ideiglenes pótlásokat.

## Megosztott rendelőlap árlistája

A megosztás árlistaválasztója kizárólag a rendelés partnerének valamelyik aktív címéhez rendelt, aktív `wholesale` vagy `distributor` árlistákat kínálja fel. Nem a bejelentkezett képviselő összes partnerének árlistáiból választunk. Egy partner több címe ugyanazt az árlistát csak egyszer jeleníti meg. Címfelhasználónál ezen belül csak a felhasználóhoz hozzárendelt aktív címek árlistái választhatók, nem a partner teljes címkörének árlistái. Több hozzárendelt cím esetén az ezekhez tartozó árlisták választhatók, de más partner árlistái nem. Partneradminnál és képviselőnél marad a rendelés partnerének teljes aktív címköre. Kisker, outlet, inaktív, más partnerhez rendelt vagy sehova nem rendelt árlista nem választható. Az alapérték a rendelés eredeti árlistája, ha jogosult; egyébként az első engedélyezett árlista, vagy üres választás.

A címfelhasználó kiválasztását a szerver friss felhasználói és cím-hozzárendelési adatokból ellenőrzi. Ha egy meglévő megosztás létrehozója címfelhasználó, a link megnyitása és minden további kérése is figyelembe veszi az aktuális címjogosultságát. A cím-hozzárendelés vagy az árlista-hozzárendelés megszűnésekor a link nem használható. A régi, létrehozó nélkül tárolt linkeknél csak a rendelés partnerének árlistakorlátja ellenőrizhető.

A szerver a létrehozáskor frissen újraellenőrzi a kiválasztást. A régi link megnyitása és a már megnyitott megosztás minden további Livewire-kérése is ellenőrzi a lejáratot, visszavonást, rendelési állapotot, szezonláthatóságot és az árlista aktuális engedélyét. Tiltott link 404-et ad, nem rögzít hozzáférési időpontot és nem módosít rendelési sort. Ha az engedély később ismét érvényessé válik, egy még nem lejárt és nem visszavont link újra használható. Végleges letiltáshoz a megosztást vissza kell vonni; az engedélytelen régi link a tulajdonos listájában általános, árlistanevet nem felfedő felirattal továbbra is visszavonható.

A megjelenített árlista, pénznem, kapcsolt kiskerárlista és megosztott mód szerver által kezelt, zárolt Livewire-tulajdonság. A linket megnyitó nem állíthatja át ezeket más árlistára. A megosztott oldal nem listázza a partner más megosztható árlistáit.

A kisker ár feloldása változatlan: a megosztáskor kiválasztott nagyker-/disztribútor-árlista `retail_price_list_id` kapcsolata mutat a kiskerárlistára. Mindkét ár a `price_list_items` táblából, a rendelés szezonjára és az adott modellre olvasódik. Nincs külön kiskerárlista-választás, automatikus pénznemkeresés vagy átváltás; hiányzó kapcsolat vagy tétel esetén a jelenlegi megjelenítő 0-t ad vissza. A megosztásban rögzített mennyiségek mentett rendelési egységára továbbra is az eredeti rendelés árlistájából származik, nem a megosztott nézeti árlistából.

Ehhez a szűkítéshez nincs migráció, függőségtelepítés vagy frontendbuild. Ellenőrzés: `OrderSharePriceListAccessTest`, `PriceListSeasonTest`, valamint a partneroldali szezon-, elérhetőségi és disztribútor-regressziós tesztek.

## Engedélyezett rendeléstípusok

Az adminban a **Partner Addresses / Partnercímek** szerkesztésénél, illetve a
partner adatlapjának címkezelőjében az **Engedélyezett rendeléstípusok** többválasztós
mező állítható. A címek listája kódokkal is mutatja a hozzárendelést.

Tárolás: `partner_address_order_type` kapcsolótábla, egyedi cím/típus párral.
Csak a címhez rendelt, aktív `order_types` választhatók új rendeléskor, minden
partneroldali szerepkörnél. A szerver is ellenőrzi az engedélyt. Üres lista
esetén nincs új rendelés; a korábbi rendeléseket ettől még lehet olvasni/exportálni.

Elsődleges alapérték a VRELO, ha engedélyezett; egyetlen más engedélyezett
típus esetén azt választjuk. Több másik lehetőségnél választani kell.
Címváltáskor az előző cím típusa nem maradhat jogosulatlanul kiválasztva.

Migráció: `2026_11_16_150000_add_address_order_types_and_pool_suggestions`.
A jóváhagyott induló beállítás szerint a migráció a már meglévő címekhez
csak az aktív VRELO típust rendeli; az új címekhez
nincs automatikus engedély. Rendelési adatot és más címbeállítást nem ír át.
Az aktív VRELO hiánya esetén a migráció leáll, nem választ másik típust.

A címek listájában és a partner adatlapjának címlistájában a kijelölt sorok
csoportos művelete: **Rendeléstípusok frissítése a poolból**. Két módja van:
minden kijelölt cím meglévő típusainak cseréje, vagy csak az üres beállítások
kitöltése. Alapértelmezett a csere, amely külön jóváhagyást kér. Partner ERP-kód
és címkód alapján párosít; azonos címkód más partnernél nem találat. A pool
üres javaslata, hiányzó vagy inaktív/nem vevői forrás esetén kihagyja a címet,
nem töröl engedélyt. Ismeretlen/inaktív típus, többértelmű forrás, megváltozott
pillanatkép vagy kijelölés esetén semmit sem módosít. Csak a címtípus-kapcsolatok
változnak, a rendelési és egyéb törzsadatok nem. A művelethez a címek
megtekintési/módosítási és a partnerpool megtekintési/átvételi jogosultsága kell.
Ugyanez az ERP-partnerpool csoportos műveletei között is elérhető: ilyenkor
csak a kijelölt poolsorokhoz párosítható, már meglévő partnercímek frissülnek.
Új cím vagy partner nem készül; a nem párosítható sorokat külön összesítjük.

## ERP-partnerpool

Új javaslatmező: `partnerpool.order_type_codes` (JSON-tömb).
SQL-alias: `ordertypes`, szabványos JSON-tömbbel:

```sql
'["VRELO","VRBELO"]' AS [ordertypes],
```

A mellékelt `infrastructure/erp/partnerpool.sql` a `[U4 LIVE]` ERP
`ols_warehouse` táblájából, `addrid` alapján képezi a javaslatot:
`whtype & 2` → `["VRBELO"]`, `whtype & 8` → `["VRZELO"]`, találat nélkül
`["VRELO"]`. A megadott `CASE`/`MIN` szabály szerint egy címhez egy típus kerül:
ha mindkettő előfordul, a VRBELO az elsődleges. Az alkalmazás ettől függetlenül
több típust is támogat a JSON-tömbben. A korábbi `both` bitmaszkjavítás, a
teljes linked-server elérési utak és a `sp.delstat=0` védelem megmaradtak.
Nem a rendelőlaptípus (`ordersheets`) mezőt
kell módosítani: az attól független, továbbra is pl. T/S/B kódokat tartalmaz.

Az admin átvételi ablak kód alapján előre kijelöli az aktív, párosítható
rendeléstípusokat; mentés előtt módosíthatók. Üresen is átvehető a cím, de így
új rendelés nem hozható létre rajta. Ismeretlen/inaktív kód figyelmeztetést ad, nem lesz belőle
automatikus jogosultság. Meglévő címen semmit nem ír felül. Beszállítónál nincs
partneroldali címtípus-engedélyezés.

Régebbi SQL-lekérdezés új mező nélkül továbbra is beolvasható, ilyenkor a
rendeléstípus-javaslat üres, és az adminnak kézzel kell választania. A Python
szinkron továbbra is csak a poolt frissíti, nem rendel hozzá jogosultságot a törzshöz.

Frissítési sorrend: alkalmazás és migráció NAS/teszt/tárhely célrendszeren,
majd az SQL-szerveren a `sync_partnerpool.py` és `partnerpool.sql` frissítése a
`C:\servio_work\preorder_partners` mappában. A konfigurációt, kulcsokat és mentett
jelszavakat nem kell cserélni. Új Python-csomag nem szükséges.

## Ellenőrzés

`PartnerSeasonContextTest`: navigáció, összesítő, hozzáférés, képviselői gombok,
tiltott rendeléstípus, címváltás, meglévő rendelések és migráció.
`PartnerPoolTest`: javaslatok, kézi eltérítés, admin szerkesztés, hibák,
meglévő címek változatlansága. `tests/Python/test_partnerpool_sync.py`: JSON-tömb,
alias, korábbi lekérdezés, hibás értékek és tranzakciós publikálás.

`PartnerPoolAddressOrderTypeUpdateTest`: mindkét listából indítható frissítés,
csere és üres mód, visszavonás, megerősítés, jogosultságok, szűrt kijelölés több
oldalon, kihagyott kijelölések, pontos címkódpárosítás, hiányzó/üres/inaktív forrás,
hibás adatok teljes visszagörgetése és megváltozott pool/kijelölés tiltása.
