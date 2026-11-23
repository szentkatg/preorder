# Letölthető admin importminták

## Modellszintű exkluzivitás (2026-11-23)

A terméktörzs `exclusivity` mezője opcionális, legfeljebb 100 karakteres szöveg
(`VARCHAR(100) NULL`). Az adminban **Exkluzivitás** néven szerkeszthető, kereshető,
rendezhető és exportálható listaoszlop. A kijelölt modellekhez a **Termékek
tömeges módosítása** művelettel is megadható vagy üresre állítható; nem jelöl
árakat felülvizsgálatra, nem korlátozza a rendelhetőséget, és nem jelenik meg
automatikusan a partneroldalon.

A termékimport mintájának `products` lapján az `exclusivity` oszlop használható.
Opcionális: hiányzó oszlop megtartja a meglévő adatot, jelen lévő üres cella
törli. Új terméknél alapból üres. A 100 karakteres korlát ékezetes betűkkel is
karakterszám, nem bájtszám; hosszabb érték validálási hibát okoz, nem csonkolódik.
Az adminűrlap és a tömeges módosítás ugyanezt a korlátot ellenőrzi.

Az **Exkluzivitás** oszlop a bekerülési érték, HUF/devizás kisker, nagyker,
disztribútori, általános kalkulációs sorok és teljes áráttekintés listáiban is
elérhető (a termékenkénti árkezelést is beleértve). Kereshető, rendezhető és
az oszlopkezelőben ki/be kapcsolható; a meglévő Excel-exportok a teljes
szöveget viszik ki. Az árprojekt terméklistája, termékfelvételi listája és
a kalkulációs hibalista szintén mutatja. Mindig a terméktörzs aktuális értékét
használjuk, nem kalkulációs pillanatképet; változása nem igényel újraszámítást.
A főcsoportonként összesített áreloszlásnak nincs egyedi modellrekordja,
ezért abba nem kerül ilyen oszlop.

Ehhez a mezőhöz a `2026_11_23_150000_add_exclusivity_to_products_table` migráció
szükséges; a meglévő termékek más mezőit nem módosítja. A korábbi, a mintafájlok
bevezetéséről szóló üzemeltetési megjegyzések ezt az új migrációt nem helyettesítik.

Az importoldalak fejlécében az **Excel-minta letöltése** művelet fájlfeltöltés és validálás nélkül is letölthető XLSX-et ad:

- Partner import: partners, partner_addresses, partner_users, address_brands, address_order_sheet_types.
- Terméktörzs import: products, colors, skus, assortments.
- Árlista import: price_lists, price_list_items.
- Beszállító és beszerzési ár import: suppliers, product_purchase_prices.
- Cikkcsere import: a korábban is elérhető, season_code / old_sku_code / new_sku_code fejlécű minta.

Az új mintákat az alkalmazás meglévő Laravel Excel-generátora készíti, nem külső vagy kézzel telepítendő fájlokat szolgálunk ki. A négy új mintában üres adatlapok és egy külön **Kitöltési útmutató** lap van: a példák és a magyarázatok csak az import által figyelmen kívül hagyott útmutatóban szerepelnek. A munkalapok neve és a fejlécsor az importálóknak megfelelő. A kódoszlopok teljes oszlopa szöveges formátumú, a dátum- és számoszlopok saját formátumot kapnak.

Nincsenek benne a régi name_en / catalog_group_name_en mezők, illetve az árlistafejben szezon. Az új anyagösszetétel, ígért szállítási dátum, képviselői partnerkód, beszállítói ország és címkód, valamint árlistakapcsolatok szerepelnek. A partner címéhez tartozó rendeléstípusokat ez az Excel-import jelenleg nem kezeli; ezek továbbra is az admin címadatlapján, illetve az ERP-poolból frissíthetők.

A letöltés ugyanazt az oldal-hozzáférést ellenőrzi, mint maga az importfelület. Nem tartalmaz adatbázisból származó üzleti vagy személyes adatot, nem módosít törzsadatot. Nincs új migráció, jogosultság, Composer-függőség vagy frontend build.

Ellenőrzés: `tests/Feature/Admin/AdminImportTemplateTest.php` a négy oldalon teszteli a letöltést és a jogosultságot, visszaolvassa a valódi XLSX-eket, ellenőrzi a munkalapokat, fejlécet, kódformátumokat, valamint a valódi importvalidátorokkal is validálja az üres sablonokat. A kitöltött sablonok visszaimportálását is teszteli, megtartott útmutató munkalappal, vezető nullás kódokkal, dátummal és tizedes beszerzési árral. A cikkcsere meglévő mintáját a `SkuReplacementImportTest` fedi le.
