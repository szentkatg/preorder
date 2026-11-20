# Belépési napló

A sikeres Laravel `Login` események adatbázis-naplózása mindkét felületen:
`web` = admin, `partner` = partnerportál. A partnerbelépés a jelszó sikeres
ellenőrzésekor kerül be, a szezonválasztás előtt. Szezonváltás, oldalfrissítés,
meglevő munkamenet használata, hibás jelszó, inaktív partnerfelhasználó,
megosztott tokenes rendelőlap és a tárhely könyvtárvédelmi belépése nem új
alkalmazásbelépés. Az „Emlékezz rám” sütivel létrejött új Laravel-belépés is bekerül.

Tárolás: `login_logs`. Mezők: ID, guard, felhasználó ID, belépéskori név és
e-mail, IPv4/IPv6 cím, UTC időpont. A két felhasználótábla miatt az azonosság
a guard és a felhasználó ID együttese. A pillanatfelvétel megmarad átnevezés,
e-mail-változás és felhasználótörlés után is. Nincs jelszó/hash, cookie,
session ID, megosztási token, teljes fejléc vagy kérési payload tárolása.

Az IP a Laravel `Request::ip()` eredménye. Nem fogadunk el kézzel feldolgozott,
tetszőleges `X-Forwarded-For` fejlécet, és nem engedélyezünk minden proxyt.
Fordított proxy mögött az alkalmazás aktuális, megbízhatóproxy-beállítása
határozza meg, hogy a kliens vagy a proxy IP-je látható. Közös céges IP/NAT
mögött több személynek is lehet azonos címe; az IP nem személyazonosító bizonyíték.

Admin: **Rendszerbeállítások → Belépési napló**, útvonal `/admin/login-history`.
Csak olvasható: nincs létrehozás, módosítás vagy törlés. Kereshető név,
e-mail, IP, ID; felületszűrés és budapesti naptári napok szerinti időszakszűrés.
Az Excel-export az aktuális szűrés minden találatát tartalmazza, ugyanazzal
a budapesti időpont-megjelenítéssel. A nyári/téli időszámítás is érvényesül.

Külön szerepkör-jogosultság: `View:LoginHistory` (`web` guard). A migráció
létrehozza, de nem adja automatikusan egyetlen normál szerepkörnek sem.
A superadmin a meglévő gate-szabállyal eléri. Jogosultság visszavonása a már
megnyitott lap következő kérését/exportját is tiltja. A napló megtekintése
és exportja személyes adatot tartalmaz, csak megfelelő kollégának adandó.

Telepítés: szokásos kódfrissítés, `php artisan migrate --force`, majd
`php artisan optimize`. Nem kell új csomag vagy frontendbuild, a napló
nem igényel ütemezést. Korábbi belépések nem rekonstruálhatók ebből.
Nincs automatikus törlés/megőrzési idő beállítva. Az adatbázis-admin
technikai hozzáférésétől ez a napló nem válik megváltoztathatatlanná.

Ha a napló mentése hibázik, a belépés nem áll le. Az alkalmazásnaplóba
`Login audit persistence failed.` jelzés kerül, kizárólag guard, user ID
és kivételosztály mellett. Ilyen időszakban hiányos lehet a belépési napló;
a tárolási hibát javítani kell. A felhasználó nem kap adatbázis-hibarészleteket.

Ellenőrzés: `tests/Feature/Auth/LoginAuditTest.php`,
`tests/Feature/Admin/LoginHistoryTest.php` és a meglévő partnerbelépési tesztek.
