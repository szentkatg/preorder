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

## Codex-engedélyezés

A script első Codex-futtatásakor a **tartós engedélyezés** lehetőséget válaszd a
konkrét script útvonalára javasolt parancsszabálynál. A szabály a
`powershell.exe -NoProfile -File <a script teljes útvonala>` prefixre szóljon,
ne általánosan a PowerShell futtatására.

Utána mindig ugyanazt a teljes scriptútvonalat használjuk. Másik worktree-be
helyezett scripthez új szabály szükséges; a mentett szabály nem írja felül a
szervezeti tiltásokat. A Git add/commit/push a már külön engedélyezett Git
parancsokat használja.
