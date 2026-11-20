# Letölthető admin importminták

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
