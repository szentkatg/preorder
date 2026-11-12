# ERP-partnerpool

## Működés és határok

A `partnerpool` címenként egy sort tartalmazó ERP-pillanatkép. A közös partneradatok ismétlődnek. A Python-program az SQL szervert elérő Windows-gépen fut; a NAS nem szükséges hozzá. A tárhely MySQL/MariaDB adatbázisába ír, ellenőrzött SSH-csatornán keresztül vagy közvetlen, ellenőrzött TLS-kapcsolattal. A szeganet.hu jelenleg tesztelt MySQL-végpontja nem hirdet TLS-t, ezért ott az SSH-módot használjuk.

A szinkron kizárólag a `partnerpool` és `partnerpool_sync_state` táblát módosítja. Nem frissít partnert, címet, beszállítót, felhasználót, jogosultságot, rendelést vagy árat. A törzsbe átvétel külön, felhasználó által jóváhagyott művelet.

Az adminmenüben: **Törzsadatok → ERP-partnerpool**. A listában a felhasználó kiválasztja a kívánt címsort, majd az **Átvétel** műveletet. Vevőnél az adott partner és az adott cím, beszállítónál az adott partnerhez tartozó egyetlen beszállítói rekord készül el. Más címek nem kerülnek be automatikusan. Egy partner több címét külön-külön lehet átvenni. Beszállítónál a kiválasztott sor címkódját és országát használjuk; az Alapcím oszlop segít a választásban.

Meglévő törzsadatot és címjogosultságot soha nem írunk felül. Új címet meglévő partnerhez is fel lehet venni. Más partnerhez tartozó, azonos `addrid` esetén az átvétel hibával leáll; nem mozgatunk címet és nem hozunk létre globálisan ütköző címkódot. Partnerportál-felhasználó, jelszó, e-mail-értesítés nem készül automatikusan. Az átvétel a `partnerpool_import_logs` táblába naplózódik a végrehajtóval és a forráspillanatkép azonosítójával, teljes kapcsolattartói adattartalom másolása nélkül.

## Adatbázis és jogosultságok

Új migrációk:

- `2026_11_10_090000_create_partnerpool_tables.php`: pool, publikált pillanatkép állapota, átvételi napló.
- `2026_11_10_090100_add_partnerpool_permissions.php`: `View:ErpPartnerPool`, `Import:PartnerPool`.

A felhasználói szerepkörnek az oldal megtekintési joga és az `Import:PartnerPool` jog kell. Vevő átvételéhez ezen felül `Create:Partner` és `Create:PartnerAddress`, beszállítóhoz `Create:Supplier` szükséges. A migráció nem oszt új jogot meglévő normál szerepkörnek; a super_admin meglévő szabálya működik.

A szinkron saját MySQL-felhasználót kapjon. Szükséges táblaszintű jogok:

- `partnerpool`: SELECT, INSERT, DELETE.
- `partnerpool_sync_state`: SELECT, UPDATE.
- Más programtáblához, a naplóhoz, DDL-hez nincs szüksége írási jogra.

Ezeket a tárhely jogosultságkezelőjével vagy szolgáltatói segítséggel kell kiosztani. Ne másold az alkalmazás teljes `.env` fájlját az SQL szerverre. Közvetlen TLS-es MySQL-elérés esetén a Távoli MySQL hozzáférésnél csak az SQL-oldali futtatógép publikus kimenő IP-címét engedélyezd. SSH-módban a MySQL a tárhely saját loopback címéről érkező kapcsolatot látja; az ehhez tartozó MySQL-felhasználói hozzáférés szükséges. SSH-hoz a futtatógépnek a tárhely SSH-portját kell elérnie. Az ERP SQL portját egyik megoldáshoz sem kell megnyitni az internet felé.

**Az éles rendszer még régebbi alkalmazásverziót használhat.** Ne futtasd rá automatikusan az összes fejlesztői migrációt, és ne válts át a fejlesztői ágra előzetes élesítési terv nélkül. Először a tesztkörnyezetben kell kipróbálni az új migrációkat, a frissítési terv szerinti kódverzióval. Éles adatbázis-mentés és külön élesítési jóváhagyás szükséges.

## A mellékelt ERP-lekérdezés változtatásai

Forrás: `infrastructure/erp/partnerpool.sql`.

1. A `both` feltétel a customer/supplier ágak elé került. Az eredeti harmadik ág elérhetetlen volt.
2. A kapcsolattartó alkérdezése is a `[U4 LIVE]` linked szervert használja. Nem feltételezünk helyi `HTO_ERP_LIVE` adatbázist.
3. A `TOP 1` kapcsolattartó kiválasztása `empid` szerint determinisztikus.
4. Az utca részei közé szóköz kerül, így nem tapadnak össze. A Python a fölösleges szóközöket összevonja.
5. `SET NOCOUNT ON` és egyetlen ODBC-kapcsolat kezeli a teljes batch-et, a `#addresses` ideiglenes táblával együtt.

Megmaradt az eredeti `NOLOCK` használat. Ez nem garantál konzisztens ERP-forrásképet; a publikálás atomikussága nem helyettesíti az ERP-oldali konzisztens olvasást. Az eredményellenőrzés a hibás/duplikált/ellentmondó kimenetet visszautasítja. Ha a lekérdezést nyugodt időszakban futtatjuk, érdemes az ERP DBA-val megvizsgálni a NOLOCK elhagyását vagy az ERP által támogatott snapshot olvasást.

Az eredeti `partner_active=1` és `address_active=1` megmaradt. A partner `delstat=0` szűrése adott, de a címek törlési/aktivitási mezőjének neve nem ismert: ha az ERP-ben ilyen van, a lekérdezést még annak megfelelően pontosítani kell.

Az ERP `countryid` mezője nem feltétlenül kétbetűs országkód. A lekérdezésben vagy a konfiguráció `country_map` objektumában lehet megfeleltetni. A szinkron megőrzi a fel nem oldott eredeti értéket; a törzsbe átvétel addig nem engedélyezett, amíg a felhasználó nem ad meg kétbetűs országkódot. Nem találunk ki országkódokat.

A `partner_phone` az eredeti lekérdezésben a cím telefonja, ezért címenként eltérhet. A többi ismétlődő partnerfejadat egyezőségét ellenőrizzük. A `default` forrásoszlop a poolban `is_default` néven szerepel. A kódok, címkódok, irányítószámok szöveges értékek maradnak, kezdő nulláikkal együtt, amennyiben az ERP szövegként adja őket.

## ERP-javaslatok és oszlopelrendezés

Az átvételi ablak a poolban kapott kódok alapján előre kitölti az árlistát,
pénznemet, nyelvet, engedélyezett márkákat és rendelőlaptípusokat. Ez csak
javaslat: valamennyi kiválasztás módosítható a jóváhagyás előtt. Az ablak
megnyitása nem hoz létre partnert. A meglévő törzsadatok és hozzárendelések
továbbra sem íródnak felül. A felhasználó végső választásait ellenőrizzük és
mentjük, nem a nyers ERP-javaslatot.

| SQL-eredmény oszlopa | Pool mezője | Tartalom |
| --- | --- | --- |
| `Language` | `language_code` | Nyelvkód, pl. HU/EN |
| `Currency` | `currency_code` | Pénznemkód, pl. HUF/EUR |
| `pricelist` | `price_list_code` | Árlistakód, pl. Wholesale_HU |
| `brands` | `brand_codes` | JSON-kódlista, pl. `["HT","DV"]` |
| `ordersheets` | `order_sheet_type_codes` | JSON-kódlista, pl. `["T","S","B"]` |
| `address type` | `address_type` | Tájékoztató szöveg; nem kerül partnercímbe és nem ad jogosultságot |

A kanonikus pool-mezőneveket is elfogadja a Python; egy mező több néven történő
visszaadása hibának számít. A kódlistáknál hibás JSON, nem szöveges vagy üres
elem, 100 karakternél hosszabb kód vagy 200-nál több elem leállítja a szinkront;
a korábbi pool megmarad. A duplikált kódokat kis-/nagybetűtől függetlenül
kiszűrjük. A régi, javaslatok nélküli lekérdezés továbbra is használható.

A párosítás kis-/nagybetűtől független, csak aktív és egyértelmű törzsbeli
kódokra. Hiányzó, inaktív vagy többértelmű javaslat esetén figyelmeztetés
látszik az ablakban, és kézzel kell pótolni a választást. Nem engedélyezünk
automatikusan minden márkát vagy rendelőlaptípust. Az árlista–pénznem egyezés
ellenőrzése változatlan.

Az Átvétel művelet a táblázat első oszlopa. Az adatmezők sorrendje és láthatósága
az oszlopkezelőben, szélességük a fejléc szélének húzásával állítható; a mentés
felhasználónként történik (`erp_partner_pool_*` beállítások). A közös tartalomhoz
igazítás és szélesség-visszaállítás is elérhető. A műveletoszlop átrendezés után
is elöl marad.

### A javaslatbővítés kihelyezési sorrendje

1. Laravel-kód és `2026_11_12_140000_add_suggestions_to_partnerpool` migráció
   a frissítendő célrendszerre, a megfelelő PHP-val `artisan migrate --force`.
   Az új három mező nullable; meglévő pool- vagy törzsadatot nem módosít.
2. Cache-törlés és újraépítés a webszerver számára olvasható jogosultságokkal.
   A privát mentések umaskját ne örökítsük át sablonfordításra.
3. Az SQL szerveren a `sync_partnerpool.py` és `partnerpool.sql` frissítése
   ugyanabban a mappában; a `partnerpool_credentials.py` maradjon mellettük.
   A konfiguráció, kulcsok és DPAPI-jelszómentés nem változik.
4. Kézi forrásellenőrző dry-run, majd a megfelelő teszt CMD-vel ellenőrzött
   frissítés. Az új Python publikálás előtt ellenőrzi a céloszlopokat, és hiányos
   séma esetén a régi pillanatképet megtartva leáll.

Az SQL `both` ága két külön bitmaszk-feltételt kapcsol össze AND-del, mert
`sp.type & 62 & 64` mindig nulla. A korábbi `sp.delstat=0` védelem, a determinisztikus
kapcsolattartó-választás és a címrészek szóközzel összefűzése megmarad.
A felhasználó megerősítése alapján a címtípustörzs is a linked server ERP-
adatbázisában található: `[U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_typeline]`.
A lekérdezés ezt a teljes hivatkozást használja, nem a helyi Kontrolling tábláját.

Ehhez a bővítéshez **migráció és az SQL szerveren fájlmásolás szükséges**,
de új Composer/Python-csomag, `npm run build` vagy `public/build` feltöltés nem.
Az ERP-n történő SQL-futtatást a helyi tesztek nem helyettesítik.

Git-parancsok külön jóváhagyott commit/push esetén:

```powershell
git add -- app/Filament/Pages/ErpPartnerPool.php app/Models/PartnerPool.php app/Services/Imports/PartnerPoolImporter.php resources/views/filament/pages/erp-partner-pool.blade.php database/migrations/2026_11_12_140000_add_suggestions_to_partnerpool.php infrastructure/erp/sync_partnerpool.py infrastructure/erp/partnerpool.sql infrastructure/erp/SUGGESTIONS-QUICKSTART.txt tests/Feature/Admin/PartnerPoolTest.php tests/Feature/DatabaseMigrationTest.php tests/Python/test_partnerpool_sync.py docs/partnerpool.md
git commit -m "Prefill ERP partner pool imports from source suggestions"
git push origin feature/pricing-module
```

A NAS kihelyezése a `deploy-nas.ps1` paranccsal, a teszt/éles tárhely frissítése
csak a megfelelő jóváhagyott kiadási ágról történjen. A tárhelyi PHP/Artisan
parancsok a dokumentum végén találhatók. A Windows-szinkron fájljai nem
frissülnek attól, hogy a webalkalmazás Gitből frissült.

## Python telepítése az SQL-t elérő Windows-gépen

Előfeltételek: Python 3.10 vagy újabb, Microsoft ODBC Driver 18 for SQL Server, megfelelő ERP/linked-server olvasási jogosultság az SQL-login számára. A tárhely felé SSH-hozzáférés és engedélyezett `direct-tcpip` továbbítás kell a helyi MySQL-hez, vagy közvetlen MySQL-elérés érvényesen ellenőrizhető TLS-tanúsítvánnyal. A Python és az ODBC-driver architektúrája egyezzen.

Az SQL szerveren már használt munkakönyvtár külön almappájába telepítsük a szinkront:

```text
C:\servio_work\preorder_partners\
```

A `sync_partnerpool.py`, `partnerpool.sql`, `partnerpool-ssh_known_hosts` és `partnerpool-sync.example.json` fájlok kerüljenek közvetlenül ebbe a mappába. A mellékelt `requirements.txt` fájlt **`requirements-partnerpool.txt` néven** másold ide, hogy egy esetleges meglévő követelményfájlt ne írjunk felül. A `run-partnerpool-sync.ps1` opcionális indító ugyanide kerülhet. Frissítésnél az új programfájlra és csomaglistára van szükség; a saját konfigurációdat ne írd felül a példával.

PowerShellben, ezen a gépen, külön Python-környezettel, hogy a többi szkript csomagjait ne változtassuk meg. A `.venv-partnerpool` új, ehhez a szinkronhoz fenntartott mappa legyen; ha már létezik, előbb ellenőrizd, mi használja:

```powershell
New-Item -ItemType Directory -Path C:\servio_work\preorder_partners -Force
py -3 -m venv C:\servio_work\preorder_partners\.venv-partnerpool
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe -m pip install -r C:\servio_work\preorder_partners\requirements-partnerpool.txt
```

A korábban a `C:\servio_work\.venv-partnerpool` helyen létrehozott virtuális környezetet ne mozgasd át: az új helyen hozd létre újra. A régi környezetet ezek a parancsok nem törlik vagy módosítják. Ha a követelményfájlt még nem másoltad át, a csomagtelepítés közvetlenül is elvégezhető:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe -m pip install "pyodbc>=5.2,<6" "PyMySQL>=1.2.4,<2" "paramiko>=4,<5"
```

A `partnerpool-sync.example.json` alapján hozz létre egy **nem Gitbe kerülő**, NTFS-jogokkal védett `C:\servio_work\preorder_partners\partnerpool-sync.config.json` fájlt. Állítsd be a helyi MSSQL-kapcsolatot, a külön MySQL-szinkronfelhasználót és a céladatbázist. SSH-módban a MySQL hostja `127.0.0.1`: ez a tárhely saját MySQL-jét jelenti, nem az SQL szerverét. Közvetlen TLS-es módban az `ssh` blokk elhagyható, és a MySQL hostja a szolgáltató által megadott TLS-es végpont. A relatív `query_file`, `ssl_ca`, `identity_file` és `known_hosts_file` útvonalak a konfigurációs fájl könyvtárához viszonyítottak. Teszthez `environment: staging` és `szeganet_preorder_test`; éleshez `environment: production` és a jóváhagyott éles adatbázis.

### Már működő konfiguráció átállítása SSH-ra

Az SSH-frissítőcsomag tartalmaz egy `setup-and-check-ssh.cmd` indítót is. A frissített program, csomaglista és nyilvános szerverkulcs mellé másolva egyetlen paranccsal elvégzi a csomagtelepítést, a konfiguráció mentését/átállítását és az írás nélküli tesztet:

```powershell
C:\servio_work\preorder_partners\setup-and-check-ssh.cmd
```

Ez a már létrehozott `.venv-partnerpool` környezetet használja; nem építi újra és nem módosítja más szkriptek Python-környezetét. Ha a következő kézi lépéseket az indító már elvégezte, nem kell újra futtatni őket.

Az új program és a nyilvános `partnerpool-ssh_known_hosts` fájl átmásolása után ez az egyetlen sor módosítja a helyi konfigurációt, a működő MSSQL-beállítások megtartásával:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\sync_partnerpool.py --config C:\servio_work\preorder_partners\partnerpool-sync.config.json --setup-ssh
```

Ez nem kapcsolódik semmilyen adatbázishoz. Előbb változatlan másolatot ment a saját konfiguráció mellé `.before-ssh-<azonosító>.bak` néven, majd atomikusan átállítja a MySQL hostját `127.0.0.1`-re és hozzáadja az SSH-beállításokat. Az ár-/partneradatokat és a konfiguráció többi részét nem módosítja. Ismételt futtatás kész beállításnál nem hoz létre újabb mentést. Már létező, eltérő SSH-beállításokat nem ír felül automatikusan.

Az alap SSH-felhasználó `szeganet`, a cél `szeganet.hu:22`. Ez **nem** a `szeganet_po_test` adatbázis-felhasználó. Az SSH-jelszót a `PARTNERPOOL_SSH_PASSWORD` változó tárolja; kézi tesztnél az alábbi kapcsoló rejtetten bekéri a hiányzó jelszavakat:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\sync_partnerpool.py --config C:\servio_work\preorder_partners\partnerpool-sync.config.json --check-target --prompt-passwords
```

Ez csak SSH-kapcsolatot nyit, a tárhely helyi MySQL-jéhez továbbít és `SELECT DATABASE(), 1` ellenőrzést végez. **Nem olvas ERP-adatot, nem hoz létre táblát és nem publikál**; a `--check-target`, `--setup-ssh` és `--apply` egymást kizáró kapcsolók. Sikeres kimenet:

```json
{"status": "ok", "target_connection": true, "transport": "ssh", "published": false}
```

Az SSH-csatorna közvetlenül a MySQL-driver socketje: nincs külső parancs, nyitva hagyandó terminál vagy helyi hallgató port. A program előbb a MySQL-sessiont, majd a csatornát és az SSH-kapcsolatot zárja le, hiba esetén is. SSH-módban kizárólag a tárhely `127.0.0.1` vagy `::1` címére engedélyezett a továbbítás. A MySQL saját TLS-e ezen a belső szakaszon nincs bekapcsolva; az internetes szakaszt a hostkulccsal ellenőrzött SSH titkosítja. SSH-hiba esetén nincs közvetlen MySQL-visszaesés, és a driver nem kapcsolódhat újra az SSH-csatornán kívül.

A mellékelt szerverkulcs a helyi gép korábban eltárolt, megbízható OpenSSH `known_hosts` bejegyzéséből származik, nem friss hálózati kulcslekérésből. Ed25519 ujjlenyomat: `SHA256:pZIRN6Lb4FOZ8dNS8BQ53/EQ4ntqhol2b1wdKsW7ioU`. A jelenlegi tárhely felé még külön ellenőrizni kell. Ismeretlen vagy megváltozott kulcsnál a program leáll; a változást a szolgáltatóval kell igazolni, nem automatikusan elfogadni. Ez nyilvános szerverkulcs, nem felhasználói privát kulcs.

Ha `SSH forwarding was refused` hibát kapsz, a szolgáltatónál engedélyeztetni kell a `direct-tcpip` csatornát a tárhely saját MySQL-jéhez. Ha ez nem megengedett, másik védett átviteli megoldás szükséges; nyilvános, titkosítatlan MySQL-re nem váltunk.

Éjszakai futtatáshoz javasolt egy külön, az SQL szerveren létrehozott SSH-kulcs. Az `ssh` blokkban a `password_env` helyett `identity_file` állítható be a privát kulcs elérési útjára; titkosított kulcsnál `key_password_env` adja a jelmondat környezeti változóját. A kulcs és a jelszavas SSH-belépés közül pontosan egy választható; nincs automatikus kulcskeresés vagy agent-/jelszó-visszaesés. A nyilvános kulcsot a tárhelyen kell engedélyezni, a privát kulcsot nem kell feltölteni oda vagy Gitbe tenni.

A két adatbázis jelszavának külön neve van: `PARTNERPOOL_MSSQL_PASSWORD` az SQL-forráshoz, `PARTNERPOOL_MYSQL_PASSWORD` a tárhelyi célhoz. Windows alatt az alább ismertetett DPAPI-mentés javasolt: a program a jelszavakat csak a saját folyamata környezetébe olvassa vissza, nem kell őket tartós Windows-környezeti változóként beállítani. Ne írd a jelszavakat a parancssori argumentumokba, JSON-fájlba vagy Gitbe. A futtató Windows-fiókhoz, a kódhoz, a konfigurációhoz és a privát SSH-kulcshoz való hozzáférést védeni kell.

Az MSSQL-kapcsolat SQL-felhasználóval és jelszóval működik. A `mssql` konfigurációs rész:

```json
"mssql": {
  "connection_string": "DRIVER={ODBC Driver 18 for SQL Server};SERVER=YOUR_SQL_SERVER;DATABASE=master;Encrypt=yes;TrustServerCertificate=no;",
  "username": "YOUR_SQL_USERNAME",
  "password_env": "PARTNERPOOL_MSSQL_PASSWORD",
  "query_file": "partnerpool.sql",
  "timeout_seconds": 300
}
```

A `SERVER` annak az SQL-példánynak a címe, amelyen a `[U4 LIVE]` linked server létezik, nem maga a linked-server alias. JSON-ban névvel rendelkező SQL-példánynál a fordított perjelet duplázni kell: `SERVER=SQLSERVER\\INSTANCE;`. A megadott SQL-login olvashassa a linked-server forrását is. A `connection_string` ne tartalmazzon `Trusted_Connection`, `Integrated Security`, `Authentication`, `UID` vagy `PWD` mezőt: az SQL-hitelesítési adatokat a szkript a két külön konfigurációs mező alapján állítja össze, a jelszó speciális karaktereit is kezelve. Korábbi Windows-hitelesítéses konfiguráció továbbra is használható, ha nincs megadva sem `username`, sem `password_env` a `mssql` részben.

Ha korábban már átmásoltad a fájlokat, a módosított `sync_partnerpool.py` fájlt újra át kell másolni az SQL szerverre. A saját konfigurációdat ne írd felül a példával; a `mssql` részét módosítsd, a MySQL-beállításokat hagyd meg.

Kézi teszt előtt PowerShellben így adható meg az SQL-jelszó, rejtett bevitellel és a parancselőzményekbe írás nélkül:

```powershell
$sqlSecret = Read-Host 'SQL Server jelszo' -AsSecureString
$env:PARTNERPOOL_MSSQL_PASSWORD = [System.Net.NetworkCredential]::new('', $sqlSecret).Password
Remove-Variable sqlSecret
```

Ez csak az aktuális PowerShell-ablak és az innen indított folyamatok számára állítja be a változót; a Feladatütemező nem örökli. A forrásellenőrző dry-run csak az MSSQL-jelszót igényli. Kézi futtatáshoz a `--prompt-passwords` is használható: a hiányzó jelszavakat rejtetten kéri be és a folyamat végén eltávolítja a beállított változókat; az eredetileg meglévő változókat megtartja. Ez önmagában nem tartós jelszótárolás. Az ismételt bekérést az alábbi DPAPI-mentés szünteti meg.

### Egyszeri, titkosított jelszómentés Windows alatt

Másold át együtt az új `sync_partnerpool.py`, `partnerpool_credentials.py` és `save-passwords-and-check.cmd` fájlokat az SQL szerver meglévő `C:\servio_work\preorder_partners` mappájába. A konfigurációt, az SQL-lekérdezést, a virtuális környezetet és az SSH-kulcsot ne írd felül.

Ugyanazzal a Windows-fiókkal, amely majd a feladatot is futtatja, lehetőleg egy friss PowerShell-ablakban indítsd ezt az egy sort:

```powershell
C:\servio_work\preorder_partners\save-passwords-and-check.cmd
```

Az indító egyszer rejtetten bekéri és titkosítva menti az összes konfigurált jelszót, majd külön kézi bekérés nélkül ellenőrzi a MySQL/SSH-kapcsolatot és az ERP-forrást. Egyik ellenőrzés sem publikál poolt. A jelmondat nélküli SSH-kulcshoz nem kér és nem tárol jelmondatot. Nem készít XML-t, nem telepít és nem engedélyez feladatot. Normál PowerShell-konzolt használj, ne ISE-t: a natív Python rejtett jelszóbekérése az ISE-ben nem megbízható.

A mentés önálló parancsa:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\sync_partnerpool.py --config C:\servio_work\preorder_partners\partnerpool-sync.config.json --save-passwords
```

A titkosított fájl a futtató felhasználó profiljába kerül: `%LOCALAPPDATA%\PreorderPartnerPool\credentials-<azonosító>.dpapi`. Nem a projektbe vagy a tárhelyre. A fájl a Windows `CryptProtectData`/`CryptUnprotectData` API-ját használja felhasználói hatókörrel, `CRYPTPROTECT_LOCAL_MACHINE` nélkül. Nincs külön, fájlban tárolt titkosító kulcs, szöveges jelszómentés vagy Linuxos álcázott alternatíva. Az írás atomikus; a titkosítás/visszaolvasás ellenőrzése megelőzi a korábbi mentés cseréjét. A DPAPI-payload verziója, konfigurációs kötése és tartalmi ellenőrzőösszege is ellenőrződik.

A beállítási fájl helye és a kapcsolódás azonosítói is részei a kötésnek. Másik konfigurációhoz, adatbázishoz, felhasználóhoz vagy SSH-hosthoz külön mentés készül: a tesztjelszavak nem kerülnek automatikusan az éles konfigurációba. Kapcsolati beállítás vagy a konfiguráció helyének megváltoztatása után ismét mentsd el a jelszavakat. A futtatási korlátok, például `min_rows` változtatása önmagában nem érvényteleníti a mentést.

Normál futáskor a program automatikusan tölti be az adott konfigurációhoz elmentett jelszavakat; a `--prompt-passwords` elhagyható. Kifejezetten a környezetben megadott érték elsőbbséget élvez. A folyamat végén az ideiglenesen betöltött változókat visszaállítja. Hibás, sérült vagy másik fiókkal nem feloldható mentésnél nem vált titkosítatlan tárolásra vagy felügyelet nélküli jelszóbekérésre. A jelszócsere ugyanazzal a `--save-passwords` paranccsal végezhető.

A DPAPI nem véd a már kompromittált futtató Windows-fiókkal szemben: az alkalmazásnak futás közben hozzá kell férnie a jelszavakhoz. Ne adj másnak hozzáférést ehhez a fiókhoz vagy az általa futtatott módosítható kódhoz. Másik gépre/fiókra telepítéskor az ottani felhasználóval újra meg kell adni a jelszavakat; a teljes projektmappa másolása nem viszi át automatikusan a feloldható titkokat.

### Kapcsolódási hiba diagnosztikája

Az `infrastructure/erp/diagnose_mssql.py` fájlt is másold a `C:\servio_work\preorder_partners` mappába, a frissített `sync_partnerpool.py` mellé. Nem kell többsoros Python-kódot a PowerShellbe illeszteni, csak ezt az egy sort:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\diagnose_mssql.py
```

A diagnosztika ugyanazt a helyi konfigurációt használja. Ha nincs SQL-jelszó a környezetben, interaktív konzolon rejtetten bekéri; visszhangos jelszóbevitelre nem vált át. A bekért jelszó csak ebben a Python-folyamatban használható, nem állítja be tartósan vagy a szülő PowerShell-ablaknak.

Csak kapcsolódást és `SELECT 1` lekérdezést végez; nem futtatja az ERP-partnerlekérdezést, nem kapcsolódik MySQL-hez és nem ír programadatot. Kimenete a hibafázist (`config`, `password`, `odbc_driver`, `connect`, `select_1`), az ellenőrzött formátumú SQLSTATE-et és rögzített diagnosztikai jelzőket tartalmazza, nem a teljes driverüzenetet vagy kapcsolati adatokat. A jelzők támpontok, nem biztos diagnózisok; sikeres kapcsolat után az eredeti szinkron forrásellenőrzését is futtatni kell.

Közvetlen MySQL-módban kötelező a TLS tanúsítvány- és hostnév-ellenőrzéssel. Céges/szolgáltatói CA esetén az `ssl_ca` mezőbe add meg a megfelelő PEM-fájlt; nyilvános, megbízható tanúsítványnál maradhat `null`. Hibás tanúsítvány vagy hiányzó TLS esetén nincs automatikus visszaesés. Az SSH-mód külön, kifejezetten beállított biztonságos szállítási mód, nem TLS-hibára aktiválódó kivétel. A belső MSSQL-en a teszt során használt `TrustServerCertificate=yes` a hitelességellenőrzés ideiglenes megkerülése; a tartós ütemezés előtt megbízható, megfelelő nevű SQL-tanúsítvánnyal rendezendő. Az SSH-mód ezt nem módosítja.

Első forrásellenőrzés, MySQL-kapcsolat és írás nélkül:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\sync_partnerpool.py --config C:\servio_work\preorder_partners\partnerpool-sync.config.json
```

Ellenőrzött tesztbetöltés:

```powershell
C:\servio_work\preorder_partners\.venv-partnerpool\Scripts\python.exe C:\servio_work\preorder_partners\sync_partnerpool.py --config C:\servio_work\preorder_partners\partnerpool-sync.config.json --apply
```

Éles betöltéshez az éles konfiguráció mellett külön `--allow-production` kapcsoló is kötelező. A szinkron alapból dry-run; ez a kapcsoló önmagában sem ír, az `--apply` is szükséges.

## CMD-indítók: kézi teszt, ütemezett éles

A tesztet **nem ütemezzük**. Kézi frissítése a meglévő tesztkonfigurációval és jelszómentéssel:

```powershell
C:\servio_work\preorder_partners\run-partnerpool-test.cmd
```

Ez valóban frissíti a teszt partnerpoolt, de nem importál automatikusan partnert a törzsbe. Napló: `logs\partnerpool-test.log`. A korábbi `partnerpool-nightly-test.xml` nem használandó; ha már importáltál ilyen feladatot, tiltsd le. A régi `--prepare-task` kapcsoló magyarázó hibával leáll; új XML nem készül.

Az éles konfiguráció külön fájl: `partnerpool-sync.production.config.json`. Kiindulópont a `partnerpool-sync.production.example.json`, nem kész kapcsolati beállítás. Környezet: `production`; céladatbázis: `szeganet_elorendeles`. Töltsd ki és ellenőrizd az éles MySQL-felhasználót, az ERP/SSH-kapcsolatokat, a tesztelt `country_map`-et és forrásparamétereket. A tesztfájlt ne nevezd át és ne írd át élesre! Az éles konfigurációhoz külön DPAPI-mentés szükséges:

```powershell
C:\servio_work\preorder_partners\save-production-passwords-and-check.cmd
```

Ez csak ment és ellenőriz, nem publikál. A meglévő tesztjelszó-mentés változatlan marad. A konfigurációs fájl helye/kapcsolati azonosítói a mentéshez kötöttek, ezért utólagos módosításuk új mentést igényel.

Az éles feltöltést végző CMD (csak az előfeltételek rendezése után indítandó):

```powershell
C:\servio_work\preorder_partners\run-partnerpool-production.cmd
```

Mindkét indító a saját mappájából, az ottani `.venv-partnerpool\Scripts\python.exe`-t használja, nem a PATH-on talált Pythont. Kötött konfigurációt használ, átadja az elvárt környezetet és adatbázist; eltéréskor még jelszóbetöltés és kapcsolódás előtt leáll. Hiányzó éles konfiguráció esetén soha nem esik vissza a tesztre. Csak az éles indító tartalmaz `--allow-production` kapcsolót. Nincs automatikus, rejtett jelszóbekérés.

Az indítók létrehozzák a `logs` könyvtárat, időbélyeggel naplózzák a START/OK/ERROR állapotot, a Python standard kimenetét és hibakimenetét. Az éles napló `logs\partnerpool-production.log`. Hiba esetén a Python kilépési kódját visszaadják a Feladatütemezőnek; siker: 0. Hiányzó fájl: 2. Nem írható naplónál nem indul el a szinkron. A naplókban nincsenek jelszavak vagy teljes driverüzenetek; hozzáférésüket korlátozd és méretüket időnként ellenőrizd/archiváld.

### Éles ütemezés előfeltételei

- Az éles Laravel-kiadásban legyen telepítve a partnerpool funkció és a hozzá tartozó migrációk. Ez a CMD-fejlesztés nem telepít éles webalkalmazást vagy sémát.
- Ellenőrzött éles céladatbázis és korlátozott jogosultságú MySQL-felhasználó, helyes SSH-hostkulcs és elérhető privát kulcs.
- Az MSSQL végleges tanúsítványellenőrzését rendezni kell: a korábbi `TrustServerCertificate=yes` ideiglenes kivétel. Az SSH-alagút a SQL Server oldali hitelességellenőrzést nem helyettesíti.
- Előbb olvasási kapcsolat-/forráspróba a kitöltött éles konfigurációval, majd jóváhagyott egyszeri kézi éles feltöltés. A futtató ugyanaz a `servio` Windows-fiók legyen, mint a DPAPI-mentésnél.

### Feladatütemező beállítása, kizárólag élesre

- Program: `C:\Windows\System32\cmd.exe`
- Argumentum: `/d /c "C:\servio_work\preorder_partners\run-partnerpool-production.cmd"`
- Kezdés helye: `C:\servio_work\preorder_partners`
- Napi időpont a felhasználó választása szerint (például 02:00, az SQL szerver helyi ideje).
- Ugyanaz a Windows-fiók; bejelentkezés nélkül is fusson, `Password` hitelesítéssel. Mentéskor a Windows-fiók jelszavát kell megadni, nem az SQL/MySQL-jelszót. A „Ne tárolja a jelszót” / S4U mód nem megfelelő a hálózat és titkosított adatok eléréséhez.
- Emelt jogosultság nem szükséges. Ha már fut, új példány ne induljon. Javasolt 30 perces futási korlát; kimaradt futás pótlása és hibák követése.

A feladatot először letiltva készítsd el. Az előfeltételek és a kézi próba után külön ellenőrizd a Feladatütemezőből indított futást is, a `0x0` eredményt és a napló `OK`/`published: true` állapotát. Az automatikus ütemezés csak ezután engedélyezendő. A beállítást a felhasználó végzi az SQL szerveren; a csomag másolása semmilyen feladatot nem regisztrál.

Az alkalmazásban látszik az utolsó sikeres frissítés; 36 óra után elavultsági figyelmeztetés jelenik meg. A kézzel frissített tesztnél ez várható, és nem ok egy tesztütemezés létrehozására.

## Sikertelen futások és adatvédelem

A forrás teljesen beolvasódik és validálódik, mielőtt bármilyen MySQL-írás történne. Üres eredmény, duplikált partnerkód/címkód, eltérő ismétlődő partnerfej, hibás típus vagy alapértelmezés szerint 20%-nál nagyobb sorcsökkenés esetén nincs publikálás. Jogos, ellenőrzött nagy csökkenéshez egyszeri `--allow-large-drop` engedély adható; üres pool akkor sem publikálható.

A MySQL-táblák InnoDB-t használnak. Egy tranzakció tölti be az új batch-et, váltja át az aktív mutatót és törli a korábbi pool sorait. Nincs TRUNCATE vagy más implicit commitot okozó DDL a szinkronban. A felület és az átvételi művelet csak a publikált batch-et látja. Egy régebben elkezdett kivonat nem írhat felül újabb publikált kivonatot. A már megnyitott átvételi ablak régi batch esetén nem hozhat létre rekordokat; friss listából újra kell választani.

Hibánál nincs félig betöltött, látható pool. Megszakadt commit-válasz esetén a kliens nem tudhatja biztosan, megtörtént-e a commit: az alkalmazásban megjelenő batch/frissítési állapotot kell ellenőrizni. A törzsbe átvételi napló megmarad a korábbi pool törlése után is.

## Tesztelés és kihelyezés

Helyi PHP-tesztekhez az `infrastructure/scripts/test-worktree.php` és a projekt SQLite memóriaadatbázisos tesztbeállítása használandó. Éles/NAS/teszt üzleti adatbázison RefreshDatabase-teszt nem futtatható.

```powershell
php -d extension=pdo_sqlite -d memory_limit=1024M infrastructure/scripts/test-worktree.php tests/Feature/Admin/PartnerPoolTest.php
python -m unittest discover -s tests/Python -p "test_partnerpool*.py" -v
```

A Python-tesztek hamisított SQL/MySQL-kapcsolatokkal ellenőrzik az atomikusságot és a hibautakat. Telepített Python-függőségekkel két natív teszt is fut helyi loopbacken, valódi Paramiko/PyMySQL kóddal: ellenőrzött SSH-csatornán MySQL-handshake és ping, illetve hibás hostkulcs visszautasítása még jelszóhitelesítés előtt. Függőségek nélkül ezek a natív tesztek kimaradnak. Ez nem bizonyítja a tárhely forwardingengedélyét, a távoli kulcs jelenlegi egyezését, az ERP konkrét sémáját vagy az ODBC-telepítést. Ezeket az SQL szerver hálózatán kell kipróbálni, először `--check-target --prompt-passwords`, majd forrásellenőrző dry-run és tesztadatbázisos betöltés útján.

Kódoldali kihelyezés a jóváhagyott Git-verzióból: `git pull --ff-only`, majd a megfelelő PHP-val `php artisan migrate --force`, `php artisan optimize:clear`, `php artisan optimize`. Composer-függőség és Vite-bemenet nem változott: új Composer-telepítés vagy frontendbuild csak emiatt nem szükséges. Külön feltöltendő frontend asset nincs. A Windows oldali Python-fájlokat, csomagokat, konfigurációt és ütemezést viszont külön telepíteni kell.

### Git-parancsok ehhez a fejlesztéshez

Az alábbi parancsok a `feature/pricing-module` munkapéldányban csak a partnerpool módosításait jelölik ki; a korábbi, még nem commitolt görgetési fejlesztést nem. A konfigurációs példába soha ne kerüljön valódi jelszó. Ezek előkészített parancsok, nem automatikusan végrehajtott lépések.

```powershell
git add -- .gitignore config/filament-shield.php app/Filament/Pages/ErpPartnerPool.php app/Models/PartnerPool.php app/Models/PartnerPoolSyncState.php app/Services/Imports/PartnerPoolImporter.php database/migrations/2026_11_10_090000_create_partnerpool_tables.php database/migrations/2026_11_10_090100_add_partnerpool_permissions.php resources/views/filament/pages/erp-partner-pool.blade.php infrastructure/erp/sync_partnerpool.py infrastructure/erp/partnerpool.sql infrastructure/erp/partnerpool-sync.example.json infrastructure/erp/requirements.txt infrastructure/erp/run-partnerpool-sync.ps1 tests/Feature/Admin/PartnerPoolTest.php tests/Python/test_partnerpool_sync.py docs/partnerpool.md
git add -- infrastructure/erp/diagnose_mssql.py tests/Python/test_partnerpool_diagnostics.py
git add -- infrastructure/erp/partnerpool-ssh_known_hosts infrastructure/erp/SSH-QUICKSTART.txt infrastructure/erp/setup-and-check-ssh.cmd tests/Python/test_partnerpool_ssh.py
git add -- infrastructure/erp/partnerpool_credentials.py infrastructure/erp/save-passwords-and-check.cmd infrastructure/erp/PASSWORDS-QUICKSTART.txt tests/Python/test_partnerpool_credentials.py
git add -- infrastructure/erp/run-partnerpool-test.cmd infrastructure/erp/run-partnerpool-production.cmd infrastructure/erp/save-production-passwords-and-check.cmd infrastructure/erp/partnerpool-sync.production.example.json tests/Python/test_partnerpool_launchers.py
git diff --cached --check
git commit -m "Add protected partnerpool CMD launchers and saved credentials"
git push origin feature/pricing-module
```

### Tárhelyi parancsok a jóváhagyott kiadás telepítésekor

Csak a megfelelő tesztelt kiadási ágon, a projekt könyvtárában, adatbázis-mentés után. Az alábbi példa nem helyettesíti az éles és a fejlesztői ág közötti korábbi eltérések rendezését.

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Üzemeltetői teendő a teljes partnerpool funkcióhoz: a két új jogosultság szerepkörhöz rendelése, a MySQL szűk jogosultságú felhasználója, a választott SSH/TLS-hozzáférés ellenőrzése, valamint a Windows oldali telepítés, védett jelszótárolás és napi feladat. A teljes partnerpool funkcióhoz **migráció szükséges; composer install, npm run build és public/build feltöltés nem szükséges**. Az utólag hozzáadott SSH-kapcsolódási bővítés önmagában Windows/Python-oldali módosítás: ehhez külön Laravel-migráció vagy NAS/tárhelyi kódtelepítés nem kell, de a Python-csomagokat és fájlokat frissíteni kell. Tényleges feltöltéshez a pooltábláknak előbb létre kell jönniük.

Hivatalos háttér:

- https://github.com/mkleehammer/pyodbc/wiki/Connection
- https://github.com/mkleehammer/pyodbc/wiki/Cursor
- https://docs.paramiko.org/en/stable/api/client.html
- https://docs.paramiko.org/en/stable/api/transport.html
- https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptprotectdata
- https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptunprotectdata
- https://learn.microsoft.com/en-us/windows/win32/taskschd/taskschedulerschema-logontype-simpletype
- https://pymysql.readthedocs.io/en/latest/modules/connections.html
- https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html
