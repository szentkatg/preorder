# Ellenőrzött éles frissítés

Az éles frissítés külön felhasználói jóváhagyást és külön belépési pontot igényel.
A NAS/teszt telepítő jogosultsága nem terjed ki rá.

```powershell
powershell.exe -NoProfile -File infrastructure/scripts/deploy-production.ps1 -Mode Check -Commit A_TESZTEN_MAR_FUTO_40_KARAKTERES_COMMIT
powershell.exe -NoProfile -File infrastructure/scripts/deploy-production.ps1 -Mode Deploy -Commit A_TESZTEN_MAR_FUTO_40_KARAKTERES_COMMIT
```

A célcommitnak helyben ismertnek és a teszten már telepítettnek kell lennie.
A parancs kizárólag a rögzített éles mappát, adatbázist, SSH-kapcsolatot és
`release/production-20261122` ágat használja. Nem importál tesztadatbázist.
Nem módosít környezeti beállítást, könyvtárvédelmet vagy függőségeket.

A jelenleg felülvizsgált migrációk név szerint engedélyezettek: termék
partneroldali láthatóság, exkluzivitás, főcsoportkód-minta. A láthatósági migráció
a már meglévő termékek új mezőjét igazra állítja; más meglévő oszlop nem változik.
Új migrációkhoz a telepítési szabály külön kódellenőrzése szükséges.

Menet: előellenőrzés, Git-publikálás, kód- és konfigurációmentés, maintenance,
teljes ellenőrzött adatbázismentés, fast-forward kódfrissítés, a megengedett
migrációk futtatása, cache-újraépítés, belső HTTP-próba, meglévő üzleti adatok
változatlanságának ellenőrzése és visszakapcsolás. A HTTP-próba csak a saját
CLI-folyamatában kerüli meg a maintenance módot, nyilvánosan nem.

A privát mentések a `/home/szeganet/.preorder-production-releases` könyvtárban
vannak, a futás kiírja a RunId-t és a mentési helyet. A kódarchívum mellett a
`.env`, a `.htaccess` és a teljes tömörített adatbázismentés is megmarad.

Hibánál nincs automatikus adatbázis-visszaállítás. Diagnózis után ugyanazzal a
célcommittal és az eredeti RunId-val a `-Mode Resume -RunId ...` ismétli az
idempotens telepítési lépéseket és az ellenőrzéseket. A megállás utáni maintenance
nem oldható fel vakon; előbb a kód/séma és az üzleti adatok ellenőrzése szükséges.

A siker feltétele a `PRODUCTION DEPLOY OK`, majd a publikus HTTPS-belépőoldalak
külön ellenőrzése. A helyi HEAD tartalmazhat új telepítési segédprogramokat is;
ezért a `-Commit` a teszten már futó alkalmazásverzióra mutasson, ne automatikusan
az új segédprogram commitjára.
