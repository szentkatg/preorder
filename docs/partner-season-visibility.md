# Szezon előkészítése a partnerportál megnyitása nélkül

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

- A bejelentkezéshez e-mail, jelszó, nyelv és szezon tartozik. Egyetlen elérhető
  szezon esetén előre kiválasztjuk; többnél választani kell. Rejtett szezon
  módosított kérésben sem fogadható el. Hibás belépés nem ment szezont.
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
