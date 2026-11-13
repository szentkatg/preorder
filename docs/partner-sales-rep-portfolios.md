# Partnerportál: képviselői partnerkörök hozzárendelése

## Beállítás az adminfelületen

Ez a Partner Users / partnerfelhasználók adatlapjának beállítása, nem a
Filament-admin felhasználók Spatie-szerepköreinek kiosztása.

1. A képviselő szerepeljen a partnertörzsben saját ERP-kóddal, például `P000444`.
   Nincs külön képviselőtábla.
2. A partnerfelhasználó szerepköre legyen **Területi képviselő** (`sales_rep`).
3. Az **Engedélyezett partnerek és képviselői partnerkörök** többválasztóban
   keresd ki az ERP-kódot vagy nevet, és jelöld ki a képviselőt. Ha már vannak
   hozzá rendelt partnerek, a felirat például:
   `P000444 - Képviselő neve — teljes partnerkör (10 partner)`.
4. A vevő partner törzsében a **Területi képviselő** mező ugyanerre a kódra
   hivatkozzon (`partners.sales_rep_erp_partner_code = P000444`). Ez a partnerpool
   átvételével is kitöltődhet.
5. A portál következő lekérdezésétől / oldalfrissítésétől a partner összes címe
   és az egyébként megengedett rendelései hozzáférhetők. Új partner vagy új cím
   felvételekor a felhasználó hozzárendelését nem kell újra menteni.

A képviselő akkor is kijelölhető, ha még nincs hozzá tartozó vevő: a később
létrehozott partnerek automatikusan megjelennek. Több képviselő is kijelölhető
egy felhasználóhoz; ugyanaz a képviselő több felhasználóhoz is hozzárendelhető.

## Megmaradó szabályok

- Az alapműködés már korábban is létezett. Nem készül egyszer lemásolt vevőlista:
  a `PartnerAccessService` a törzsbeli aktuális képviselő-ERP-kódot vizsgálja.
- A régi `partner_user_partners` hozzárendelés tárolja az egyedi partner- és a
  képviselőválasztást is. Nincs új tábla, mező vagy jogosultsági objektum.
- A saját partner ERP-kódjára hivatkozó partnerkör szintén hozzáférhető a
  `sales_rep` szerepkörnél; ez a korábbi működés változatlan.
- Az egyedi partner- és címengedélyek az örökölt partnerkör mellett megmaradnak.
  Ha egy partnert másik képviselőhöz sorolnak, az örökölt hozzáférés megszűnik,
  de a külön, közvetlenül megadott engedély továbbra is érvényes lehet.
- A képviselő kijelölésének törlése az örökölt hozzáférést azonnal visszavonja.
- A `partner_admin` és `address_user` szerepkör nem kap ilyen örökölt jogot.
  Az aktív címek, márkák, rendelőlaptípusok és a partneroldalon látható szezonok
  meglévő további szűréseit a módosítás nem változtatja meg.
- Üres vagy csak szóközös ERP-kód nem adhat hozzáférést a képviselő nélküli
  partnerekhez. A közvetlenül kijelölt partner ettől még elérhető marad.

A partnerfelhasználó-lista **Partner / képviselő kijelölések** oszlopa a tényleges
kijelölések számát mutatja, nem a dinamikusan örökölt összes partnerét. Egy
képviselő kiválasztása tehát továbbra is egy kijelölés, akkor is, ha tíz vevőt érint.

## Ellenőrzés és kihelyezés

- `tests/Feature/Partner/PartnerAccessTest.php`: két felhasználó ugyanazt a
  képviselőt kapja, utána tíz új partner, új címek és rendelések is hozzáférhetők
  egyetlen további pivot-hozzárendelés nélkül; átsorolás, visszavonás, más
  szerepkörök és üres ERP-kód korlátai is ellenőrzöttek.
- `tests/Feature/Admin/PartnerUserPortfolioTest.php`: az ERP-kódos keresés,
  partnerkör-felirat, a vevő nélküli képviselő kijelölhetősége és az adminoldali
  tényleges mentés/törlés ellenőrzése.
- A szezonláthatósági, összesítési és adminjogosultsági regressziók is futtatandók.

Git-parancsok külön jóváhagyott kihelyezéshez:

```powershell
git add -- app/Filament/Resources/PartnerUsers/Schemas/PartnerUserForm.php app/Filament/Resources/PartnerUsers/Tables/PartnerUsersTable.php app/Services/Partner/PartnerAccessService.php tests/Feature/Partner/PartnerAccessTest.php tests/Feature/Admin/PartnerUserPortfolioTest.php docs/partner-sales-rep-portfolios.md
git commit -m "Clarify dynamic sales representative portfolio assignments"
git push origin feature/pricing-module
powershell.exe -NoProfile -File infrastructure/scripts/deploy-nas.ps1
```

Tárhelyen csak a jóváhagyott kiadási ág frissítése után, a megfelelő projektmappában:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Ehhez a módosításhoz nem kell migráció, Composer/Python-telepítés, frontendbuild,
kézi assetfeltöltés vagy új jogosultságkiosztás. A PHP/Blade-cache megfelelő
webes jogosultságokkal épüljön újra. Partnerhozzárendeléseket a kihelyezés nem
hoz létre automatikusan; az admin jelöli ki a kívánt képviselőt.
