# Árprojekt-paraméterek és kerekítési szabályok másolása

## Használat

Az **Árképzési paraméterek** és az **Árkerekítési szabályok** listáján:

1. Jelöld ki a másolandó sorokat; a szűrés és a kijelölés a már meglévő módon működik.
2. Csoportos műveletek → **Paraméterek másolása**, illetve **Kerekítési szabályok másolása**.
3. Válassz meglévő célárprojektet. Csak nem lezárt, megtekinthető és módosítható
   projekt választható, a kijelölt forrásprojektek nem.
4. Az ablak megmutatja a kijelölt, újként másolható és ütköző sorok számát.
   Ütközéskor kötelező külön dönteni: **meglévő sorok kihagyása**, vagy
   **felülírás a forrás adataival**. Nincs automatikus felülírási alapértelmezés.
5. A Másolás gomb végrehajtja a műveletet. A Mégse vagy az előnézet elkészítése
   nem ír az adatbázisba. Az eredmény jelzi az új, felülírt, kihagyott,
   változatlan és felülvizsgálatra jelölt ársorok számát.

A felülírás a meglévő célrekord azonosítóját megőrzi; azonos értékek esetén nem
mentjük újra feleslegesen. Kihagyáskor a meglévő célrekordok egyáltalán nem
változnak, de az ütközésmentes kijelölt sorok másolódnak. A források mindig
változatlanok, akár lezárt projektből is másolhatók megfelelő olvasási joggal.

## Mi számít ütközésnek?

Paraméter: azonos paramétertípus + szűkítés + az adott szűkítéshez tartozó ország/
főcsoport/modell + árlista + pénznem. A szűkítésnél nem használt, korábbi
kitöltésből visszamaradt mezők nem alkotnak külön kulcsot; ezeket az új vagy
felülírt sorban nullázzuk. Az országkódot nagybetűsre normalizáljuk.

Kerekítési szabály: azonos ártípus + árlista + pénznem. A név, kerekítési mód,
egység, tizedesek és korrekció a másolandó tartalom, nem a kulcs része.

Az aktív/inaktív állapotot is másoljuk, de nem használjuk az ütközési kulcsban.
Az üres árlista vagy pénznem globális beállítást jelent, és nem azonos egy
konkrét árlistához/pénznemhez tartozó beállítással.

Ha a kijelölésben ugyanarra a kulcsra több forrássor van, a program nem dönt
önkényesen: kulcsonként egyet kell kijelölni. Több forrásprojektből egyidejűleg
is másolhatók különböző kulcsú sorok. Ha a célban ugyanarra a kulcsra több
rekord van, az érintett ütközések kihagyhatók; felülíráshoz előbb rendezni kell
a duplikációkat. Nincs automatikus törlés vagy sor-összevonás.

## Szezonok és árak

A modell-, főcsoport-, árlista- és pénznem-hivatkozások változatlanul mennek át.
**Nem keresünk automatikusan másik szezonbeli termékpárt.** Eltérő szezonnál a
modellszintű paraméter továbbra is az eredeti konkrét termékre mutat, ezért
az ilyen sort külön ellenőrizni, szükség szerint átállítani kell. Ezt a
felugró ablak is jelzi. A globális/főcsoportos/országszintű beállításokra ez
a termékazonosítóhoz kötött korlátozás nem vonatkozik.

Az árértékeket, jóváhagyásokat, kalkulációs snapshotokat és publikált
`price_list_items` adatokat nem másoljuk és nem írjuk felül. Nincs automatikus
újraszámítás vagy jóváhagyás. A számítást befolyásoló aktív beállítás-változás
konzervatívan a célprojekt összes meglévő, nem bekerülési ársorát
felülvizsgálatra jelöli. A meglévő outlet-modellesemények is lefutnak.
Csak kihagyott vagy teljesen változatlan sorok esetén nem történik új mentés.

## Jogosultság és biztonság

Nincs új jogosultságobjektum:

- A másolandó típus listázási és létrehozási joga kell, a konkrét forrássorhoz
  megtekintési joggal (`ViewAny`, `Create`, `View`).
- A forrásprojektek megtekinthetők kell legyenek. A célprojekthez a listázáson
  felül `View:PricingProject` és `Update:PricingProject` szükséges.
- Felülíráshoz a konkrét meglévő célparaméter/szabály `Update` joga is kell.
  Enélkül csak a kihagyás kínálható fel; a szerver közvetlen hívással sem enged
  jogosulatlan felülírást.
- A másolás tranzakcióban, determinisztikus sorrendű projekt- és sorzárolással
  fut. Előbb minden ütközést és jogosultságot ellenőrzünk, csak utána írunk.
- A megerősítés felhasználóhoz, forrásokhoz és célállapothoz kötött HMAC tokennel
  védett. Ha közben változott egy forrás vagy ütköző célrekord, illetve új
  ütközés keletkezett, új előnézet és döntés kell. Az **Előnézet frissítése**
  gomb ezt a kijelölés elvesztése nélkül megteszi.
- Ha a cél közben lezárult, a másolás leáll. Mentési hiba esetén a korábbi
  másolások és a kapcsolódó outlet-felülvizsgálati jelölések is visszagördülnek.

## Ellenőrzés

Célzott tesztek:

```powershell
php -d memory_limit=384M -d extension=pdo_sqlite -d extension=sqlite3 infrastructure/scripts/test-worktree.php tests/Feature/PricingConfigurationCopyTest.php
```

A PHP/Livewire tesztek mindkét listát és a valódi előnézeti mezőfrissítést,
ütközésválasztást, felülírást, kihagyást, Mégsét, elavult/hamisított állapotot,
jogosultságokat, zárolási életciklust, pontosságot és tranzakciós visszagördítést
ellenőrzik. Böngészős vizuális ellenőrzés nem történt.

A kapcsolódó teljes árképzési regresszió eredménye: **357 teszt, 2940 ellenőrzés,
sikeres**. A két korábbról ismert, fix admin-resource darabszámot váró tesztet
kihagytuk; az elvárásaikat nem módosítottuk. A PHP-kódformázás és a Git
whitespace-ellenőrzés is sikeres.

## Telepítés – csak külön kérésre

**Későbbi kiegészítés:** az anyagösszetétel szerinti szűkítéssel és az explicit
termékkijelöléssel együtt kiadva már szükséges adatbázis-migráció és előzetes
ütközésellenőrzés. Ennek lépései: [pricing-project-products.md](pricing-project-products.md).
Az alábbi „nem szükséges migráció” az eredeti, önálló másolási fejlesztésre vonatkozik.

- Migráció: **nem**.
- Composer install: **nem**.
- npm build: **nem**.
- Manuális fájlfeltöltés vagy `.env`-módosítás: **nem**.
- Új lapjog kiosztása vagy Shield-generálás: **nem**; a meglévő jogokat használjuk.
- Másolás után szükség lehet a célprojekt árainak újraszámítására és jóváhagyására.

A `.worktrees/pricing-module` könyvtárból:

```bash
git add -- app/Filament/Resources/PricingParameters/Tables/PricingParametersTable.php app/Filament/Resources/PricingRoundingRules/Tables/PricingRoundingRulesTable.php app/Filament/Support/Actions/CopyPricingConfigurationAction.php app/Services/Pricing/PricingConfigurationCopyService.php tests/Feature/PricingConfigurationCopyTest.php docs/pricing-configuration-copy.md
git commit -m "Copy selected pricing parameters and rounding rules between projects"
git push origin feature/pricing-module
```

NAS:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel, csak külön engedélyezett, éles ágba integrált release után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```
