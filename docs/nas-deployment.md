# NAS-frissítés egy paranccsal

A Windows alatt futó `infrastructure/scripts/deploy-nas.ps1` a Synology NAS
`preorder-v2-dev-app` konténerét frissíti a meglévő `preorder-admin` segédprogramon
keresztül. A célgép mindig `ds.szeganet.hu:500`; az éles cPanel környezetet nem érinti.

Az ágat és a célcommitot a scriptet tartalmazó helyi worktree-ből veszi.
A helyi és NAS-ág, a GitHubon lévő commit és a repository ellenőrzése után
csak fast-forward frissítés engedélyezett. Nem vált ágat, nem töröl helyi
módosításokat, és nem készít automatikusan commitot.

## Használat

A megfelelő worktree gyökeréből, commit és push után:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Csak ellenőrzés, telepítés nélkül:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
```

Az ág opcionálisan külön is megadható; egyeznie kell a worktree és a NAS ágával:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -Branch feature/pricing-module
```

## A frissítés lépései

1. Helyi és NAS Git-állapot, ág, origin és publikált commit ellenőrzése.
2. Git fetch, a célcommit ellenőrzése, majd fast-forward merge.
3. `composer install --no-interaction --prefer-dist --optimize-autoloader`, ha
   a `composer.json` vagy `composer.lock` változott. A NAS fejlesztői környezet,
   ezért a fejlesztői függőségeket is megtartja.
4. `php artisan optimize:clear`.
5. `php artisan migrate --force` (ha nincs új migráció, nincs adatbázis-módosítás).
6. `php artisan optimize` és a végleges commit ellenőrzése.

Bármely hibánál megáll. Sikert csak a végén megjelenő `DEPLOY OK` jelent.
Nem végez automatikus rollbacket. Egyszerre csak egy ilyen NAS-frissítés futhat.
A `-CheckOnly` a kapcsolati és Git-előfeltételeket ellenőrzi; nem teszteli a
Composer telepítését vagy a migrációk sikerességét, és a helyi, még nem commitolt
változások nem kerülnek a célcommitba.

Ha Vite-bemeneti fájl változott, a script a working tree frissítése előtt megáll:
a frontend buildet Windows alatt kell elkészíteni, és a teljes `public/build`
könyvtár feltöltését külön kell megszervezni. A Blade/PHP módosítások nem
igényelnek frontend buildet.

## Összecsukható admin-menü

Az admin panel a Filament beépített `sidebarFullyCollapsibleOnDesktop()`
beállítását használja. Asztali méreten a felső sáv balra mutató nyila teljesen
elrejti a menüt; a háromvonalas menügomb visszanyitja. A tartalom a felszabaduló
helyre terjeszkedik, mert a panel teljes szélességű (`Width::Full`).
A mobilmenü működése megmarad. A nyitott/zárt asztali állapotot a Filament
ugyanabban a böngészőben helyi tárolóban megjegyzi; nem szerveroldali felhasználói
beállítás. A táblázatok meglévő ResizeObserver figyelése a méretváltozást kezeli.

Ellenőrzés: 3 kapcsolódó teszt, 12 assertion sikeres (panelbeállítás, renderelt
nyitó/záró gombok és rugalmas tartalom, táblázatos görgetés assetjei).
Nincs új migráció, Composer install, npm build, kézi fájlfeltöltés vagy
jogosultság-változtatás. A partnerportált ez nem érinti.

Git/NAS a módosított worktree-ben, külön kért kihelyezéshez:

```powershell
git add -- app/Providers/Filament/AdminPanelProvider.php tests/Feature/Admin/AdminAuthorizationTest.php docs/nas-deployment.md
git commit -m "Allow fully collapsing the admin sidebar on desktop"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Menü és árképzési jogosultságjavítás közös telepítése

A két árképzési oldal egységesen külön oldaljogot és az árkalkulációs sorok
View any jogát ellenőrzi. A szállítás% / vám% műveletek, megerősítő hívások,
általános sor-szerkesztés és tömeges törlés, illetve az árprojekt bekerülési
újraszámítása szerveroldali jogosultságellenőrzést is kapott.
A pontos szerepkör-beállítások a `docs/huf-retail-pricing.md` Jogosultságok részében
találhatók. A régi szerepköröknek nem adunk automatikusan új oldaljogokat.
Nincs új migráció, Composer install, npm build, kézi feltöltés vagy .env módosítás.
Külön Shield-generálás sem szükséges, a permission rekordot a szerepkör mentése
hozza létre, ha még hiányzik.

Ellenőrzés: 63 kapcsolódó teszt, 528 assertion sikeres. A külön admin-menü
és görgetési tesztek is sikeresek. Az új jogosultsági tesztek 14 esetet fednek le,
beleértve a közvetlen URL-eket, külön oldaljogokat, csak megtekintő szerepkört,
hamisított szerkesztési/törlési és megerősítő kéréseket, szolgáltatáshívásokat,
az egyenként hiányzó jogokat és a szerkesztés közbeni jogosultság-visszavonást.
A teljes csomag két korábbról ismert, rögzített admin-erőforrás-darabszámot
elváró tesztje változatlanul elavult; ezeket nem módosítottuk.

A közös változtatás Git/NAS parancsai:

```powershell
git add -- app/Providers/Filament/AdminPanelProvider.php app/Filament/Pages/PricingCostCalculations.php app/Filament/Pages/PricingHufRetailCalculations.php app/Filament/Resources/PricingCalculationRows/Tables/PricingCalculationRowsTable.php app/Filament/Resources/PricingProjects/Pages/EditPricingProject.php app/Filament/Resources/PricingProjects/Pages/ViewPricingProject.php app/Services/Pricing/CostCalculationAuthorization.php app/Services/Pricing/CostCalculationService.php app/Services/Pricing/PricingParameterAdjustmentService.php tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/PricingAuthorizationTest.php tests/Feature/HufRetailCalculationTest.php docs/huf-retail-pricing.md docs/nas-deployment.md
git commit -m "Secure pricing workspace actions and allow collapsing the admin sidebar"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

cPanel csak külön jóváhagyott élesítéskor, az éles ágba integrálás után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

## Teljesítmény és HTTP-tömörítés

Az `infrastructure/docker/nginx/default.conf` gzip-beállításokat tartalmaz,
hogy a HTML-t tartalmazó Livewire JSON-válaszok is tömörítve menjenek át a hálózaton.
Az app-only telepítő ezt a fájlt csak a Git-repositoryban frissíti. A compose fájl
egy host-oldali fájlt mountol az Nginx-be; a tényleges útvonalat ellenőrizni kell,
nem szabad feltételezni, hogy megegyezik a konténerben lehúzott Git-fájllal.
Konfigurációváltozáskor a telepítő `NGINX ACTION REQUIRED` jelzést ad.

Egyszeri NAS-üzemeltetői lépések, a host terminálján, megfelelő sudo joggal:

1. Azonosítsd a `default.conf` runtime forrását:

   ```bash
   sudo /usr/local/bin/docker inspect preorder-v2-dev-nginx --format '{{range .Mounts}}{{if eq .Destination "/etc/nginx/conf.d/default.conf"}}{{.Source}}{{end}}{{end}}'
   ```

2. A kapott pontos fájlról készíts mentést. Ebbe a meglévő konfigurációba vidd át
   a projekt `default.conf` új gzip direktíváit. Egyedi root/upstream/TLS-beállítást
   ne írj felül. Az első aktiváláshoz szükséges új blokk:

   ```nginx
   gzip on;
   gzip_vary on;
   gzip_comp_level 5;
   gzip_min_length 1024;
   gzip_proxied expired no-cache no-store private auth;
   gzip_types application/json application/javascript text/css text/plain image/svg+xml;
   ```

3. Előbb szintaxisellenőrzés, és csak sikeres ellenőrzés után graceful reload:

   ```bash
   sudo /usr/local/bin/docker exec preorder-v2-dev-nginx nginx -t
   sudo /usr/local/bin/docker exec preorder-v2-dev-nginx nginx -s reload
   ```

   Hibás szintaxisnál ne futtasd a reloadot; állítsd vissza a mentett fájlt,
   és ismételd meg az ellenőrzést. Konténer-/adatbázis-újraindítás nem szükséges.

4. Ellenőrizd a böngésző Network nézetében egy árlista-módosítás vagy rendezés
   `/livewire/update` válaszát: `Content-Encoding: gzip`, `Vary: Accept-Encoding`.
   A **transferred** méretet nézd, ne a kitömörített **resource size** értéket;
   az utóbbi továbbra is nagy lesz. A szűrés, ár/státusz, szorzó/átlag és kijelölés
   helyességét is ellenőrizd. Az eredeti gzip direktívák visszaállítása és újabb
   `nginx -t` / reload visszavonja ezt az üzemeltetési változtatást.

A jelenlegi jelszó nélküli `preorder-admin artisan` segédparancs nem kezel
Nginx-mountot, config-másolást vagy reloadot. Ne adj általános Docker/root jogot
csak emiatt; az üzemeltető egyszer elvégezheti a fenti lépéseket. Ha később ezt is
automatizáljuk, pontosan erre a konténerre és ellenőrzött configcélra korlátozott
host segédparancs szükséges. cPanel-élesítéshez külön webszerver-beállítás kell;
oda ezt az Nginx-konfigurációt nem telepítjük.

## Codex-engedélyezés

A script első Codex-futtatásakor a **tartós engedélyezés** lehetőséget válaszd a
konkrét script útvonalára javasolt parancsszabálynál. A szabály a
`powershell.exe -NoProfile -File <a script teljes útvonala>` prefixre szóljon,
ne általánosan a PowerShell futtatására.

Utána mindig ugyanazt a teljes scriptútvonalat használjuk. Másik worktree-be
helyezett scripthez új szabály szükséges; a mentett szabály nem írja felül a
szervezeti tiltásokat. A Git add/commit/push a már külön engedélyezett Git
parancsokat használja.
