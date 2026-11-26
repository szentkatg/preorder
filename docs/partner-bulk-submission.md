# Több rendelés beküldése

A rendelésválasztó oldalon két, egymás mellett elhelyezett fül van:
**Rendelés generálása/megnyitása** és **Rendelések listája**. Egyszerre csak az egyik
tartalma látható; külön összecsukó gombok nincsenek. Ha a kiválasztott szezonban
van hozzáférhető rendelés, alapból a lista nyílik meg; egyébként a
generálás/megnyitás. A rendelőlapról és összesítőjéből visszatérve mindig a lista
nyílik meg, az utolsó rendelés törlése után is. A címkereső csak a címválasztót
szűri, az összesítő fül keresése és szűrői
pedig az ottani rendeléslistára vonatkoznak. A két tartalom fülváltáskor nem
szűnik meg, így a keresések, szűrők és kijelölések megmaradnak. Az összesítő
adatok csak az összesítő fül első megnyitásakor töltődnek be. A fülek a
nyílbillentyűkkel, valamint Home/End billentyűkkel is válthatók.

A partner rendelésválasztó rendeléssorainak jelölőnégyzetei után a **Több rendelés
beküldése** gombbal indítható. A megerősítő ablak megmutatja a kijelölt és a már
korábban beküldött rendelések számát. Mégsem esetén nem változik semmi.

A **Visszaigazoló e-mail küldése** jelölőnégyzet alapból bejelölt. Kikapcsolva
a beküldés csak a státuszt és a beküldés időpontját állítja; sem Excel-csatolmány,
sem e-mail nem készül, a CC-címnek sem. A megerősítéskor rögzített választás
a teljes kötegre érvényes, feldolgozás közben már nem változik. Új megerősítő
ablak megnyitásakor ismét bejelölt az alapértelmezés. Az egyedi beküldés
e-mail-küldési viselkedése változatlan.

Az összes partneroldali szerepkör használhatja, kizárólag az aktuálisan
hozzáférhető címek kiválasztott szezonú rendeléseire. A teljes kijelölést
megerősítés előtt ellenőrizzük: idegen cím vagy másik szezon rendelése miatt
nem indul el egy jogosult részhalmaz beküldése sem. Feldolgozáskor is újra
ellenőrizzük a felhasználó aktivitását és a rendeléshez való hozzáférést.

Rendelésenként egy rövid Livewire-kérés történik, külön, kis modal-komponensben.
A teljes rendeléstáblázatot nem rendereljük újra minden lépésnél. Nem kell hozzá
queue worker, migráció vagy frontend build. A folyamat végén frissül a lista és
a felső rendelésválasztó, a szűrők megmaradnak, a kijelölés kiürül.

Beküldéskor a státusz `submitted`, a beküldés ideje az aktuális időpont. A korábbi
egyedi beküldés és a tömeges funkció közös szolgáltatást használ. A már beküldött
rendelést nem módosítjuk, újabb visszaigazolást sem küldünk hozzá. Az állapotváltás
adatbázis-tranzakcióban, sorzárral történik, az e-mail-generálás a zár elengedése
után fut.

A visszaigazolás rendelésenként a bejelentkezett felhasználóhoz megy, a korábbi
CC-beállítással és Excel-csatolmánnyal. E-mail- vagy csatolmánykészítési hiba
nem vonja vissza a már sikeres beküldést: külön figyelmeztetés és hivatkozási
számlista jelzi. A többi rendelés feldolgozása folytatódik. A hibás rendeléseket
és az e-mail-hibákat nem keverjük össze.

A folyamatjelző és az eredmény a felület HU/EN nyelvét követi. A feldolgozás
végéig az oldalt nyitva kell tartani. Elhagyásakor a már teljesült beküldések
megmaradnak; a még el nem indult rendelések nem küldődnek be. Újbóli kijelölésnél
a már beküldöttek automatikusan kimaradnak. Nincs a háttérben futó tartós feladat.

Beküldött rendelés visszanyitásához a területi képviselő a rendelőlap tetején,
közvetlenül a **nyelvválasztó előtt** találja a **Visszaállítás piszkozatba** gombot,
a kategóriaváltó navigációtól elkülönítve.
Ez a gomb az összesítőoldalról átkerült a rendelőlapra. Megerősítés után a
státusz piszkozat lesz, a beküldési idő törlődik; a tételek változatlanok és
újra szerkeszthetők. Visszanyitáskor nincs e-mail, megosztott linken pedig nem
érhető el ez a művelet.
