# Szezon előkészítése a partnerportál megnyitása nélkül

## További termékek előkészítése egy már nyitott szezonban (2026-11-23)

A terméktörzsben külön `visible_to_partners` (**Partnerportálon látható**) mező
választja el a rendelhetőséget az aktív/árazható állapottól. Új termék alapból
rejtett, a migráció a meglévő termékek korábbi elérhetőségét megtartja.
Az inaktív termék a láthatóság bekapcsolásától sem válik rendelhetővé.

W27 új modelljeinek előkészítéséhez:

1. Az importban `active=1`, `visible_to_partners=0` értékeket használj.
2. Vedd fel őket az árprojektbe, és végezd el az árképzést a szokásos módon.
3. Amikor megnyithatók a partnereknek, a **Products** listában jelöld ki őket.
4. **Csoportos műveletek → Termékek tömeges módosítása → Partnerportálon látható → Igen**,
   majd a darabszámot tartalmazó megerősítéssel mentsd. Ugyanígy el is rejthetők.

A mező egyedi szerkesztésnél, a lista oszlopai között és külön igen/nem szűrőként
is elérhető. A meglévő termékmódosítási jog kell hozzá, nincs új jogosultság.
Az importminta `products` lapján szerepel az új, opcionális oszlop.
Hiányzó oszlop vagy üres cella meglévő terméknél nem változtat a láthatóságon,
új terméknél rejtett állapotot jelent. `1`/`0`, igaz/hamis Excel-cella,
`true`/`false`, `yes`/`no`, `igen`/`nem` is elfogadott; hibás érték esetén a
modell importja validálási hibát kap. Az oszlop sorrendje tetszőleges.

A rejtett termék kimarad a partner- és megosztott rendelőlapokból, modellkeresésből,
katalóguscsoportokból, a választható márka/rendelőlap kínálatából és a partner
Excel-rendelőlapsablonokból. A közvetlen SKU-mentés és az import (az előnézet után
újra ellenőrizve is) kihagyja a rejtett terméket, nulla mennyiséggel sem törli
a korábbi rendeléstételt. A tárolt rendeléstételek és az ERP-export alapjául szolgáló
rendelési adatok nem íródnak át vagy törlődnek a láthatóság módosításától.
A szezon láthatósága ettől külön feltétel marad, az árprojekt publikálása pedig
nem kapcsolja be automatikusan sem a szezon, sem a termék partneroldali láthatóságát.

Üzemeltetés: a `2026_11_23_130000_add_partner_visibility_to_products_table` migráció
szükséges. Nincs új függőség vagy frontendbuild. Telepítéskor a kódváltás és a
migráció rövid maintenance ablakban történjen; a fejlesztés önmagában nem telepít.

## Használat

A **Szezonok** adminoldalon külön **Partnerportálon látható** kapcsoló található,
a listában pedig oszlop és háromállapotú szűrő. A meglévő szezonmódosítási jog
szükséges hozzá; nincs új szerepkör vagy jogosultságobjektum.

1. Hozd létre az új szezont. A kapcsoló alapból **kikapcsolt**, akkor is, ha az
   `Aktív` mezőt bekapcsolod.
2. Az adminban töltsd be a termékeket, beszerzési árakat és árfolyamokat.
3. Hozz létre árprojektet; rendeld hozzá a termékeket, számold ki és hagyd jóvá az árakat.
4. A projekt lezárásával publikálhatod a szezon árlistatételeit. **Ez nem kapcsolja
   be a szezon partneroldali láthatóságát.**
5. Az előrendelés megnyitásakor külön kapcsold be a **Partnerportálon látható** mezőt.

Az árprojekt szerkeszthetőségét továbbra is a projekt státusza határozza meg,
nem a szezon aktív vagy látható jelzője. A termékek a kalkulációhoz aktívak
maradhatnak; elrejtéshez nem kell azokat inaktiválni.

Belépéskor a partner a **látható** szezonok közül választ. A látható, de már
inaktív szezon is választható a régi rendelések eléréséhez. A partner főoldala
és minden további menüpont csak a belépéskor kiválasztott szezont használja.
A láthatóság kikapcsolása az adott szezon minden partneroldali hozzáférését letiltja.

## Belépéshez kötött szezonválasztás

- A belépés kétlépcsős: először e-mail, jelszó és felületi nyelv adható meg.
  Sikeres hitelesítés után külön, kötelező modálban kell szezont választani.
  A rendelési felület szezon nélkül továbbra sem érhető el. Egyetlen elérhető
  szezont előre kijelölünk, de azt is meg kell erősíteni. Rejtett szezon
  módosított kérésben sem fogadható el. Hibás belépés nem nyit modált.
- A modál nem csukható be Esc-pel vagy háttérkattintással; a **Vissza a belépéshez**
  gomb kijelentkeztet a partnerportálról. Újratöltéskor a modál visszaáll,
  nem szükséges ismét jelszót megadni. Jelszót a modál nem tárol. A modál
  bejelentkezett felhasználóhoz kötött, böngészőből nem hamisítható az állapota,
  és inaktivált felhasználó nem folytathatja vele a belépést.
- A hitelesítés session- és CSRF-token-cseréje után teljes GET-betöltés nyitja
  meg a modált. A szezon megerősítése után is teljes navigáció történik;
  nem marad régi token az előző Livewire-dokumentumból. A tokenellenőrzést
  nem kapcsoljuk ki, a sikertelen POST-okat nem játsszuk automatikusan újra.
- A választást a munkamenet `partner.season_id` kulcsa tárolja. Belépéskor
  töröljük a korábbi rendelésválasztást és összesítőszűrőket; kijelentkezéskor
  a szezon is törlődik, a nyelv viszont megmarad.
- A fejléc **Szezonváltás** gombja a `/partner/select-season` oldalra visz.
  Itt új jelszómegadás nélkül lehet elérhető szezont választani. Csak a
  **Kiválasztott szezon megnyitása** gomb váltja át a munkamenetet és törli a
  korábbi rendeléskijelölést/szűrőket; az oldal megnyitása vagy a **Mégse** nem.
  A bejelentkezés és a nyelv megmarad, rendelést és rendelési tételt nem módosítunk.
  Az oldal bejelentkezést igényel, de még kiválasztott szezon nélkül is elérhető.
  Inaktív felhasználóval vagy időközben elrejtett szezonnal nem lehet váltani.
- A rendelésválasztó kitölti a rendelkezésre álló képernyőszélességet. A szezon
  jól látható fejlécben szerepel; új rendelésnél, összesítésnél és lefedettségnél
  nincs külön szezonválasztó. Az új rendelés automatikusan a belépési szezonba kerül.
- A rendelőlap tetején a szezon kódja/neve, a márka és a rendelőlaptípus szerepel.
  A márka és a típus neve a meglévő fordítási rendszerből, a választott nyelven jön.
- A rendelésválasztó és a rendelőlap fejlécében külön **Nyelv** választó is van,
  a belépésnél elérhető aktív felületi nyelvekkel (jelenleg HU/EN). A váltás
  azonnali, nem navigál el: megtartja a szezont, rendelést, szűrőket és kijelölést,
  illetve az új rendelés még el nem mentett hivatkozási számát is. A nyelvet a
  munkamenet és a korábbi `partner_locale` cookie megjegyzi; a következő oldalak
  is ezt használják. Rendelés, cím és partner törzsben tárolt nyelvét nem írjuk át.
  A külön rendelésösszesítő a nyelvfüggő feliratokat és adatokat újrarajzolja,
  de a betöltést továbbra is csak akkor végzi el, ha már be volt töltve.
  A megosztott rendelőlapon is váltható a felület nyelve belépés nélkül;
  ez nem módosítja a megosztást vagy a munkamenet szezonját.
- A `RequirePartnerSeason` middleware minden védett partneroldalon és az onnan
  érkező Livewire-kéréseken is érvényes. Hiányzó/elrejtett/törölt választásnál
  a szezonválasztó oldalra irányítunk, nem kérünk új bejelentkezést. A már megnyitott lapok szezonját és a közvetlen
  hivatkozásokat is ellenőrizzük. A böngésző a Livewire szezonmezőit nem írhatja át.
- A listák, összesítések, Excel-exportok és az import célrendelései is csak a
  kiválasztott szezonhoz tartozhatnak. Az összesítés-cache felhasználó, szezon és
  nyelv szerint különül el. A partner/cím jogosultságok ettől függetlenül megmaradnak.
- A tokenes, jelszó nélkül megosztott rendelőlap kivétel: mindig a megosztott
  rendelés saját szezonját használja, nem igényel belépési szezonválasztást, és
  nem módosítja a bejelentkezett partner munkamenetének szezonját. A láthatóság,
  lejárat és visszavonás ellenőrzése változatlanul érvényes rá.

Ehhez a munkamenetes bővítéshez nincs új adatbázismező vagy migráció, jogosultság,
függőség vagy frontend build. Az alkalmazás/route/view cache frissítendő.
A korábbi, szezonválasztás nélküli nyitott munkamenetek a szezonválasztó oldalra
jutnak, új belépés nélkül. Valódi kijelentkezés vagy lejárt munkamenet esetén
továbbra is szükséges a belépés. Másik szezonban nyitva hagyott lapról nem
engedünk utólag menteni az aktuális szezonváltás után.
Automatizált ellenőrzés: `PartnerSeasonContextTest` és a meglévő partner-,
szezonláthatósági, rendelésösszesítő-, ERP-export- és adminregressziós tesztek.

## Védelem

- A szezonválasztókból, a rendeléslistákból és a képviselői összesítésekből
  kimaradnak a rejtett szezonok. A korábbi összesítés-cache sem fedheti fel őket.
- A közvetlen szezon-, cím-, márka-, termék-, rendelőlap- és összesítő-URL nem
  kerülőút. A meglévő partner/cím jogosultságok ezen felül továbbra is szükségesek.
- A megnyitott szezonhoz/rendelőlaphoz kötött Livewire-oldalak minden következő
  kéréskor frissen ellenőrzik a jelzőt. Elrejtés után nem lehet rajtuk további
  adatot lekérni, exportot indítani vagy mennyiséget menteni.
- A rendelésválasztó nem fogad el rejtett szezonazonosítót; a korábbi sessionből
  visszaolvasott rejtett szezonválasztást és a hozzá tartozó rendelésválasztást törli.
- A rendeléslefedettség listája és exportja is csak látható szezonokat használhat.
- Az Excel-import forrásrendelése és a fejlécben kért célszezon is ellenőrzött.
  A kódra/névre keresés zárójelezett feltétel, így a név szerinti keresés sem
  kerülheti meg a láthatósági szűrést. Tömeges importkor minden fájlt újra ellenőrzünk,
  nem hagyatkozunk a korábban elkészült előnézetre.
- A megosztott link rejtett szezonra nem nyílik meg, belépés nélkül sem. Sikertelen
  megnyitáskor a hozzáférés időpontját sem írjuk át. Már megnyitott megosztásból
  sem lehet tovább menteni a szezon elrejtése után.
- Elrejtés nem törli a rendeléseket, árakat vagy linkeket. Újra láthatóvá tételkor
  egy még érvényes, nem visszavont megosztás ismét használható. Végleges tiltáshoz
  a megosztást külön vissza kell vonni.

Korábban letöltött fájlt vagy a böngészőben már megjelent adatot nem lehet
utólag visszavonni; a védelem az új szerverkéréseket korlátozza.

## Migráció és telepítés

Új migráció: `2026_10_28_120000_add_partner_visibility_to_seasons_table`.
Új mező: `seasons.visible_to_partners`, nem nullable logikai mező.

**Az összes meglévő szezon látható értéket kap, az inaktívak is**, így nem
veszítjük el a meglévő rendelésválasztóban elérhető történeti szezonokat.
Egy meglévő előkészítés alatt álló szezont ezért telepítés után külön kell
elrejteni. Az újonnan létrejövő szezonok alapértéke az adatbázisban és az
adminűrlapon is rejtett.

Lefuttatása nem változtatja a szezon `active` jelzőjét, az árprojekt státuszát,
az árakat vagy a meglévő rendelési adatokat. Nincs globális Eloquent scope:
az adminoldali listák és kalkulációk továbbra is látják a rejtett szezonokat.

A visszagörgetés eltávolítja az új jelzőt, és ezzel megszünteti ezt a védelmet:
nem elég pusztán rollbacket futtatni az új kód mellé. Kód és séma együtt
állítandó vissza. Telepítés előtt adatbázismentés, a kód- és sémaváltáshoz
rövid maintenance ablak javasolt, hogy az új kód ne fusson a mező létrehozása előtt.

Szükséges a migráció és a szokásos cache-frissítés. Composer install, npm build,
kézi fájlfeltöltés, új jogosultság vagy `.env` módosítás nem szükséges.
NAS- és éles telepítés csak külön jóváhagyás után történik.

Git a pricing worktree-ben, a korábbi dátumjavítás fájljaitól külön:

```powershell
git add -- app/Filament/Resources/Seasons/SeasonResource.php app/Filament/Resources/Seasons/Tables/SeasonsTable.php app/Livewire/Partner/AddressShow.php app/Livewire/Partner/BrandShow.php app/Livewire/Partner/CatalogGroupOrder.php app/Livewire/Partner/Concerns/RequiresVisiblePartnerSeason.php app/Livewire/Partner/Concerns/HandlesMultiAddressExcelImport.php app/Livewire/Partner/Dashboard.php app/Livewire/Partner/OrderSelector.php app/Livewire/Partner/OrderSummary.php app/Livewire/Partner/PartnerOrderCoverage.php app/Livewire/Partner/ProductList.php app/Livewire/Partner/SalesRepOrderSummary.php app/Livewire/Partner/SeasonShow.php app/Models/OrderShareLink.php app/Models/Season.php app/Services/Partner/PartnerSeasonVisibility.php app/Services/Partner/PartnerAccessService.php app/Services/PartnerOrderCoverageService.php database/migrations/2026_10_28_120000_add_partner_visibility_to_seasons_table.php tests/Feature/Partner/PartnerSeasonVisibilityTest.php tests/Feature/Partner/PartnerAccessTest.php tests/Feature/PriceListSeasonTest.php tests/Feature/PricingProjectPublicationTest.php docs/partner-season-visibility.md
git add -- app/Livewire/Partner/SharedCatalogGroupOrder.php
git commit -m "Separate partner season visibility from pricing preparation"
git push origin feature/pricing-module
```

Az engedélyezett NAS-telepítésnél az ismert `deploy-nas.ps1` végzi a
frissítést/migrációt/cache-építést. A maintenance ablak külön egyeztetendő.
Az éles rendszerre csak az összeállított, jóváhagyott kiadásból:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Mobilos szezonválasztás és munkamenet

A bejelentkezés utáni kötelező szezonválasztó modálban, illetve a későbbi
szezonváltó képernyőn az útmutató állandóan látszik. Nem szükséges a natív
`title` tooltipre hagyatkozni: érintőképernyőn az nem megbízható. A szöveg
magyarul és angolul is megjelenik, a választó `aria-describedby` kapcsolattal
hivatkozik rá. A szezon kötelező kitöltése és a szerveroldali ellenőrzés változatlan.

A tárhely könyvtárvédelmének HTTP 401-es jelszókérése és a Laravel 419-es
session/CSRF-hibája eltérő réteg. A jelszóablak hiánya önmagában nem jelenti a
könyvtárvédelem hiányát, mert a böngésző megjegyezheti a hitelesítést. Ha friss
privát munkamenetben működik a belépés, normál módban viszont nem, vizsgálandó
a megmaradt webhelyadat és a régi/visszaállított oldal; ez önmagában nem bizonyít
konkrét szerverhibát. Tiszta és privát munkamenetben is ismétlődő, közvetlenül
a könyvtárjelszó után jelentkező 419-et nem tekintünk ezzel megoldottnak;
az első kérés módját és a hitelesítés/session folyamatát külön kell vizsgálni.
Nem kapcsoljuk ki a CSRF-ellenőrzést vagy a könyvtárvédelmet.

A `PortalCsrfDiagnostics` a partner/admin/Livewire 419-es válaszainál a privát
alkalmazásnaplóba csak technikai jelzőket ír: kérésmód, HTTPS, süti előfordulása
és olvashatósága, token jelenléte, Authorization fejléc jelenléte és Android/Firefox
jelző. Jelszót, felhasználónevet, tokent, sütiértéket, IP-címet, teljes URL-t vagy
User-Agentet nem naplóz. A 419-es válasz és a védelem változatlan; a diagnosztika
nem végez újrapróbálkozást. Naplózási hiba sem változtathatja 500-ra a választ.
