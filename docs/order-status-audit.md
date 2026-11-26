# Rendelési eseménynapló

A partneroldali egyedi és tömeges beküldés, valamint a visszanyitás közös
szolgáltatása minden tényleges státuszváltásról egy sort ír az
`order_status_logs` táblába. A puszta megerősítő ablak megnyitása, a Mégsem,
a már beküldött rendelés ismételt beküldése és a már piszkozat rendelés ismételt
visszanyitása nem hoz létre új naplósort. A jogosultságot minden művelet újra
ellenőrzi. A napló nem pótol visszamenőleg korábbi eseményeket, és nem általános
SQL- vagy admin-törzsszerkesztési audit.

Tárolt adatok: rendelésazonosító és hivatkozás, partnerkód/-név, címkód, szezon,
partnerfelhasználó-azonosító és név/e-mail-pillanatkép, ellenőrzött kliens-IP,
művelet, egyedi/tömeges mód, előző/új státusz, UTC-időpont, e-mail kérése és
eredménye. A felhasználó vagy a rendelés későbbi törlése nem törli az előzményt.
Nem mentünk jelszót, tokent, sütit, teljes HTTP-fejlécet, levéltörzset,
csatolmányt vagy nyers kivételüzenetet. Az IP-t a Laravel Request szolgáltatja;
nem bízunk meg önkényesen a továbbított fejlécekben.

A státuszváltozás és az eseménysor létrehozása azonos tranzakcióban történik:
naplóbeillesztési hiba esetén a státuszváltozás is visszagördül. Az e-mail nem
az adatbáziszár alatt készül. E-mail nélkül `not_requested`, visszanyitásnál
`not_applicable` az eredmény. Kért e-mailnél előbb `pending`, majd `sent`,
`failed` vagy `no_recipient` kerül a sorba. Hiba esetén csak a kivételosztályt
őrizzük meg, a beküldést nem vonjuk vissza.

A `sent` a helyi levelezési művelet hiba nélküli befejezését jelenti, nem a
címzetthez való kézbesítés bizonyítékát. Folyamatmegszakadás vagy a küldési
eredmény mentésének hibája esetén `pending` maradhat: ezt a felület
„Folyamatban / eredmény nem ismert” állapotként mutatja, nem sikerként vagy
biztos küldési hibaként. A technikai napló külön, titkok nélküli hibajelzést kap.

Admin menü: **Rendelések → Rendelési eseménynapló**. Külön webes jogosultság:
`View:OrderStatusHistory`; szuperadminnak elérhető, más szerepkörnek külön
engedélyezni kell. A partnerportálos jogosultság önmagában nem ad hozzáférést.
A lista csak olvasható, rendezhető, kereshető, többértékű művelet-, mód-,
felhasználó-, szezon- és e-mail-eredményszűrőkkel, valamint budapesti naptári nap
szerinti időszakszűréssel. Az időpontok kijelzése és Excel-exportja budapesti idő.
Az export az összes szűrt sort tartalmazza, a látható oszlopokkal.

Telepítéshez új migráció szükséges:
`2026_11_26_090000_create_order_status_logs_table`. Ez csak az új naplótáblát és
a hozzáférési jogosultságot hozza létre, meglévő rendelést nem változtat meg.
