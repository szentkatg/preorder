# Kijelölt rendelések ERP-exportja és cikkcserék

## Használat a partnerportálon

A rendelésválasztó **Rendelések összesítése** listájában jelöld ki a kívánt rendeléseket, majd válaszd a **Kijelölt rendelések ERP-exportja** gombot. A funkció kizárólag aktív, `sales_rep` szerepű partnerfelhasználónak látható és hívható. Az adminbejelentkezés önmagában nem ad hozzáférést.

A felugró ablakban megadható a közös **Megjegyzés** (legfeljebb 250 karakter) és a kötelező **Kért szállítási határidő**. A dátumválasztó mellett a szerver az `ÉÉÉÉ-HH-NN` és `ÉÉÉÉ.HH.NN` formátumot is elfogadja. Az értékek az összes kiválasztott rendelés minden exportált sorára érvényesek. A Mégse nem módosít semmit.

Az ablak megnyitásakor rögzítjük a kijelölt rendelésazonosítókat; a letöltéskor a szerver frissen ellenőrzi a felhasználó szerepét, aktív állapotát, valamint az összes rendeléshez tartozó cím és szezon hozzáférhetőségét. Jogosulatlan vagy közben elérhetetlenné vált rendelés esetén az egész export leáll; nem készül részleges fájl. A fájl tartalma a friss rendelési sorokból, nem a képernyő gyorsítótárazott összesítéseiből készül.

## Első munkalap: ERP rendelés

Pontosan a minta 23 oszlopa, változatlan sorrendben:

`Vállalat`, `Document`, `Rend.dátum`, `Partnerkód`, `Címkód`, `BOLT`, `Pénznem`, `Száll.mód`, `Fiz.mód`, `Fiz.feltétel`, `Vevői ref.`, `Megjegyzés`, `Cikkszám`, `Megnevezés`, `Főcsoport név`, `Főcsop.t.1 név`, `Főcsop.t.2 név`, `EAN-kód`, `Mennyiség`, `Ár`, `Engedmény`, `Raktár`, `Száll.hidő`.

- Vállalat: mindig `HUPS`.
- Document: a rendelés `order_types.code` értéke (például `VRELO`).
- Rend.dátum: az export készítésének napja, Europe/Budapest időzónában.
- Partnerkód és Címkód: a rendelés partnerének ERP-kódja, illetve a cím `addrid` értéke.
- BOLT: a rendelés címének neve.
- Vevői ref.: a rendelés hivatkozási száma.
- Megjegyzés és Száll.hidő: a felugró ablakban megadott érték.
- Cikkszám és Megnevezés: a végül exportált SKU `sku_code` és `sku_name` értéke.
- Mennyiség: a rendelésben szereplő SKU-mennyiség, az esetleges cserék utáni összevonással.
- A többi oszlop kifejezetten üres marad. Nem számolunk árat és nem adunk automatikus kedvezményt.

**A gyűjtők nem bomlanak fel.** Saját gyűjtő-SKU-jukkal és rendelt gyűjtőmennyiségükkel kerülnek be. A komponensekre felvitt csere nem érinti a gyűjtőt; szükség esetén a gyűjtő saját cikkszámára kell felvinni a cserét. A csere 1:1 mennyiségű SKU-átirányítás, nem csomagolási átváltás.

A rendelések partnerkód, azon belül címkód, hivatkozási szám és belső rendelésazonosító szerint követik egymást. Egy rendelés sorai egyben maradnak; azon belül a céltermék modellkódja, a színkód és a **sizes.sort_order** határozza meg a sorrendet. A méret neve nem ABC-sorrendben rendeződik. Azonos méretsorrend esetén a SKU-kód az utolsó rendezési kulcs.

A kódok, hivatkozások és megjegyzések valódi szövegcellák, ezért a vezető nullák megmaradnak, a `=` kezdetű bevitel nem válik Excel-képletté. A dátumok numerikus Excel-dátumok `yyyy.mm.dd` megjelenítéssel, a mennyiségek valódi számok. Az ERP-munkalapon nincs összesítő vagy egyéb extra sor.

## Második munkalap: Ellenőrzés

Rendelésazonosító, szezon, partnerkód/-név, címkód, bolt, rendeléstípus és hivatkozási szám mellett látható a **Változatlan db**, **Cserélt db**, **Törölt db**, **TOTAL**, **Rendelt db** és **Eltérés**. A `db` itt rendelési SKU-egységet jelent: gyűjtőnél gyűjtőmennyiséget, nem a csomag belső darabszámát.

A TOTAL képlete: változatlan + cserélt + törölt mennyiség. A Rendelt db a cserék alkalmazása előtti rendelési sorok mennyiségösszege. Az Eltérés képlete TOTAL mínusz Rendelt db, elvárt értéke nulla. Az utolsó sor oszloponként összesít. Az üres vagy teljes egészében törölt rendelés is szerepel az ellenőrzésben.

## Admin: Rendelések → Cikkcserék

Három mező: **Szezon**, **Régi cikkszám (SKU)**, **Új cikkszám vagy TÖRÖLT**. A cikkszámok az adott szezon létező SKU-i legyenek. A `TÖRÖLT` (kisbetűvel beírva is) az érintett mennyiséget kizárja az első munkalapról, és a törölt kategóriába számolja.

Egy szezonban egy régi cikkszámhoz csak egy szabály tartozhat. A mentés ellenőrzi a hiányzó SKU-kat, a szezoneltérést, az önmagára mutató és körkörös cseréket. A szezon szintű adatbáziszár a párhuzamos ciklikus szabályfelvitelt is védi. Egyirányú cserelánc követhető a végső SKU-ig vagy TÖRÖLT-ig; egy mennyiséget a lánc hosszától függetlenül csak egyszer számolunk. Azonos cél-SKU-ra kerülő mennyiségek rendelésen belül összeadódnak; külön rendelések nem vonódnak össze.

**Az eredeti rendelés, annak SKU-i, árai, státusza és ERP-azonosítója nem módosul.** Ez az export nem állít státuszt „ERP-be töltve” értékre; az ERP importálását később kell végrehajtani. A korábban letöltött fájlt az új szabályok nem módosítják.

## Jogosultság és kiadás

Új adminobjektum: `SkuReplacement`, a szokásos `ViewAny`, `View`, `Create`, `Update`, `Delete`, `DeleteAny` jogosultságokkal. A migráció ezeket létrehozza, de normál szerepkörnek automatikusan nem osztja ki. A super_admin meglévő működése érvényes. A listában szezon szerinti többválasztós szűrő, keresés, rendezés és Excel-export elérhető.

Kiadáskor szükséges a `2026_11_16_120000_create_sku_replacements.php` migráció, majd a szokásos Laravel/Filament cache-frissítés. Új Composer-/npm-függőség és frontendbuild nem kell. Az alkalmazás meglévő Laravel Excel/PhpSpreadsheet exportmegoldását használjuk, hogy a NAS-on és a tárhelyen ugyanúgy működjön, mint a többi export.

Ellenőrzés: `ErpOrderExportTest`, `SkuReplacementTest`, `OrderSummaryFiltersTest`, `PartnerAccessTest`, `PartnerSeasonVisibilityTest`, valamint a migráció- és általános admin-jogosultságtesztek. A fájlteszt a ténylegesen generált XLSX-et újraolvassa és ellenőrzi az oszlopszerkezetet, szöveg-/szám-/dátumtípusokat, képletvédelmet, valamint a mennyiségi egyeztetést.
