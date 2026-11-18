# Kijelölt rendelések ERP-exportja és cikkcserék

## Használat a partnerportálon

A rendelésválasztó **Rendelések összesítése** listájában jelöld ki a kívánt rendeléseket, majd válaszd a **Kijelölt rendelések ERP-exportja** gombot. A funkció kizárólag aktív, `sales_rep` szerepű partnerfelhasználónak látható és hívható. Az adminbejelentkezés önmagában nem ad hozzáférést.

A felugró ablakban megadható a közös **Megjegyzés** (legfeljebb 250 karakter) és a kötelező **Kért szállítási határidő**. A dátumválasztó mellett a szerver az `ÉÉÉÉ-HH-NN` és `ÉÉÉÉ.HH.NN` formátumot is elfogadja. Az értékek az összes kiválasztott rendelés minden exportált sorára érvényesek. A Mégse nem módosít semmit.

Az ablak megnyitásakor rögzítjük a kijelölt rendelésazonosítókat; a letöltéskor a szerver frissen ellenőrzi a felhasználó szerepét, aktív állapotát, valamint az összes rendeléshez tartozó cím és szezon hozzáférhetőségét. Jogosulatlan vagy közben elérhetetlenné vált rendelés esetén az egész export leáll; nem készül részleges fájl. A fájl tartalma a friss rendelési sorokból, nem a képernyő gyorsítótárazott összesítéseiből készül.

## Első munkalap: Munka1

A munkalap neve mindig pontosan `Munka1`, a felület nyelvétől és a tartalomtól függetlenül. Az ERP ezt a munkalapnevet keresi. A második munkalap neve továbbra is `Ellenőrzés`.

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

Mindkét cikkszámmező kereshető választó: `SKU-kód – SKU-név` feliratot mutat, és a beírt kód- vagy névrészletet tartalmazó cikkekre szűkül, kis-/nagybetűtől függetlenül. A keresés szerveroldali, egyszerre legfeljebb 50 cikket ad vissza. A régi cikknél az adott szezonban már más cseresorhoz rendelt SKU-k nem választhatók; szerkesztéskor a megnyitott rekord saját régi SKU-ja megmarad. Az új cikknél nincs ilyen kizárás: több régi SKU ugyanarra az újra mutathat. A `TÖRÖLT` külön választható és kereshető. Szezonváltás törli a két korábbi cikkválasztást. A listában a régi és az új SKU neve külön oszlopban is látható, kereshető és rendezhető.

Egy szezonban egy régi cikkszámhoz csak egy szabály tartozhat. A mentés ellenőrzi a hiányzó SKU-kat, a szezoneltérést, az önmagára mutató és körkörös cseréket. A szezon szintű adatbáziszár a párhuzamos ciklikus szabályfelvitelt is védi. Egyirányú cserelánc követhető a végső SKU-ig vagy TÖRÖLT-ig; egy mennyiséget a lánc hosszától függetlenül csak egyszer számolunk. Azonos cél-SKU-ra kerülő mennyiségek rendelésen belül összeadódnak; külön rendelések nem vonódnak össze.

**Az eredeti rendelés, annak SKU-i, árai, státusza és ERP-azonosítója nem módosul.** Ez az export nem állít státuszt „ERP-be töltve” értékre; az ERP importálását később kell végrehajtani. A korábban letöltött fájlt az új szabályok nem módosítják.

## Cikkcserék importálása Excelből

A Cikkcserék lista tetején az **Importálás Excelből** gomb nyitja az importoldalt. Az **Excel-minta letöltése** három szöveges oszlopot tartalmaz:

| season_code | old_sku_code | new_sku_code |
| --- | --- | --- |
| W27 | régi SKU-kód | új SKU-kód vagy TÖRÖLT |

A példában szereplő SKU-k helyére létező kódokat kell írni. A cikkszámokat szövegként add meg, a vezető nullákat megőrizve. Egy fájlban több szezon is lehet; a két SKU-nak soronként ugyanahhoz a megadott szezonhoz kell tartoznia. Gyűjtőnél a saját gyűjtő-SKU szerepel, nincs felbontás.

Az első munkalapot olvassuk, a fejléc alapján, nem oszlopsorrend szerint. A magyar `Szezon`, `Régi cikkszám`, `Új cikkszám / TÖRÖLT` fejlécek is használhatók. XLSX és XLS támogatott, legfeljebb 10 MB, 10 000 adatsor és 100 oszlop. Képletet nem értékelünk ki: az érintett kódcellákban képlet nem használható.

1. Töltsd fel a fájlt, majd válassz ütközéskezelést. Alapból **Meglévő cserék kihagyása**; a **Meglévő cserék felülírása** külön választás, és módosítási jogosultságot igényel. Azonos célra mutató, már létező szabály változatlan marad.
2. **Ellenőrzés és előnézet**: új, felülírandó, kihagyott és változatlan sorok száma, valamint a SKU-k nevei és a sorhibák. Legfeljebb az első 100 sor/hiba látható, de a teljes fájlt ellenőrizzük. Ez még nem ment.
3. Hibátlan előnézet után **Importálás**, majd külön megerősítés. Bármely hiba esetén az egész csomag mentése elmarad. Ellenőrizzük a fájlon belüli ismétlődést, a hiányzó/más szezonbeli SKU-kat és a teljes végállapot csereláncait is.

Mentéskor ismét beolvassuk és ellenőrizzük a fájlt. Ha az előnézet óta a fájl, az importmód vagy az érintett törzs megváltozott, új előnézet szükséges. A mentés egy tranzakcióban, szezonzárakkal történik; csak a cikkcsere-törzs változik, rendelési sor nem.

Az importhoz a `ViewAny:SkuReplacement` és `Create:SkuReplacement`, felülíráshoz ezenfelül az `Update:SkuReplacement` jogosultság szükséges. Külön új jogosultság vagy adatbázis-migráció ehhez az importbővítéshez nem kell. Ellenőrző teszt: `SkuReplacementImportTest`, valódi XLSX/XLS-fájlokkal és a felületi feltöltés–előnézet–megerősítés folyamatával.

## Jogosultság és kiadás

Új adminobjektum: `SkuReplacement`, a szokásos `ViewAny`, `View`, `Create`, `Update`, `Delete`, `DeleteAny` jogosultságokkal. A migráció ezeket létrehozza, de normál szerepkörnek automatikusan nem osztja ki. A super_admin meglévő működése érvényes. A listában szezon szerinti többválasztós szűrő, keresés, rendezés és Excel-export elérhető.

Az eredeti funkció kiadásakor szükséges volt a `2026_11_16_120000_create_sku_replacements.php` migráció. A kis memóriaigényű export frissítéséhez új migráció, Composer-/npm-függőség vagy frontendbuild nem kell, csak a szokásos kód- és cache-frissítés.

### Nagy rendelésállományok exportja

A partnerportál ERP-exportja a projektben már telepített OpenSpout könyvtárral, ideiglenes fájlba, soronként írja az XLSX-et. Nem épít fel az összes cellát tartalmazó PhpSpreadsheet-munkafüzetet. A rendelési tételeket rendelésenként, 500 soros adatbázisblokkokban olvassa, csak a szükséges SKU-, termék-, szín- és méretadatokkal. Egy rendelésen belül a cél-SKU-k kompakt adatait tartja meg az összevonáshoz és a rendezéshez; a teljes export sorai nem kerülnek egy közös tömbbe. A `build()` megmaradt kompatibilis, memóriabeli exportelőállításként, de a partnerportál nem ezt hívja.

Az oszlopszélességeket írás közben méri, a Megjegyzés oszlop továbbra is 40 egység széles és tördelt. A két munkalap neve, oszlopsorrendje, adattípusai, fejléc- és oszloprögzítése, szűrője, valamint az ellenőrző képletek és előre kiszámított értékeik megmaradnak. Az ellenőrző képletek gyorsítótárazott eredményeinek véglegesítése csak a kis `Ellenőrzés` munkalapot érinti, a nagy `Munka1` XML-jét nem tölti memóriába.

Az ideiglenes fájl nem nyilvános, csak a teljes, sikeres ellenőrzés után tölthető le, és a válasz elküldésekor törlődik. Feldolgozási hiba esetén a részleges fájl is törlődik. A Livewire letöltési átadása továbbra is a kész, tömörített fájlt közvetíti, tehát a letöltési válasz méretével arányos memóriahasználat marad; ez nem az összes Excel-cella memóriabeli tárolása. Egy munkalap Excel-sorkorlátjának túllépése érthető hibaüzenetet ad: nem hagy el sorokat és nem hoz létre az ERP által figyelmen kívül hagyott extra munkalapokat.

Ellenőrzés: `ErpOrderExportTest`, `ErpOrderXlsxWriterTest`, `SkuReplacementTest`, `OrderSummaryFiltersTest`, `PartnerAccessTest`, `PartnerSeasonVisibilityTest`, valamint a migráció- és általános admin-jogosultságtesztek. A fájlteszt a ténylegesen generált XLSX-et újraolvassa és ellenőrzi az oszlopszerkezetet, szöveg-/szám-/dátumtípusokat, képletvédelmet, valamint a mennyiségi egyeztetést. A nagy fájlos teszt 50 000 adatsort, a sorszámokat, az összesítéseket és az írás legfeljebb 24 MiB többletmemóriáját ellenőrzi; külön teszt fedi a több adatbázisblokkot, az üres/törölt rendeléseket, a késői hiba utáni takarítást és a sorkorlát védelmét.

2026-11-18-i helyi terhelési próba: Windows/PHP 8.3.32, 128 MiB PHP-memóriakorlát, generált 200 rendelés × 1000 adatsor × 23 oszlop. A fájlíró 200 000 adatsort készített, 10 MiB teljes PHP-memóriacsúccsal; a kész XLSX 10,85 MiB, az írás ideje 216,36 másodperc volt. A sorszámot az elkészült ZIP munkalap-XML-jének folyamatos visszaolvasása ellenőrizte. Ez a fájlíró önálló mérése, nem a teljes adatbázisos/Livewire-letöltési útvonalé és nem a NAS/tárhely sebességének mérése. A partnerportál és a cikkcsere kapcsolódó regressziós csomagja 167 teszttel sikeres volt.
