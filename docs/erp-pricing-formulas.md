# Képletes ERP árexport

Az árprojekt adatlapján az **ERP árexport (Excel)** művelet párosítóablakában
minden árkategória forrása lehet a hozzá illő publikált árlista vagy a **Képlet…**
lehetőség. Az eddigi közvetlen árlistaválasztás és automatikus ajánlás megmarad.
Ha nincs adott pénznemű/típusú árlista, a megfelelő exportoszlop képlettel akkor is
kitölthető. Képlet nélkül a korábbi üres érték marad.

## Használat

1. Válaszd a kívánt oszlopnál a **Képlet…** forrást.
2. Válassz építőelemet: publikált árlista, szezonárfolyam (pénznemmel), fix szám.
3. Az **Érték hozzáadása** gombbal illeszd a képlet végére.
4. A műveleti gombokkal illeszd be a `+`, `−`, `×`, `÷`, `(`, `)` elemeket.
5. A nyilakkal átrendezhetők, az `×` gombbal törölhetők az elemek.
   A **Képlet ürítése** az adott oszlop képletét törli.
6. Állítsd be az adott képlet végeredményének kerekítését, ellenőrizd a szöveges
   képletet, majd indítsd az exportot.

Példák:

```text
Nagyker_EU ÷ 25
(Disztribútor_EUR + 2) × 1,1
Disztribútor_EUR × EUR-árfolyam ÷ CZK-árfolyam
```

Az árfolyam iránya mindig **1 devizaegység = X HUF**. EUR → CZK váltáshoz ezért
mindkét deviza árfolyama szükséges: 20 EUR × 400 HUF/EUR ÷ 16 HUF/CZK = 500 CZK.
A HUF árfolyama 1. A választó kiírja az aktuális szezonárfolyamot, illetve jelzi,
ha az adott aktív pénznemhez nincs használható aktív szezonárfolyam.

## Kerekítés

A képlet alatt oszloponként külön beállítható:

- **Kerekítés lépése**: `0,01` = két tizedes; `0,1` = egy tizedes; `1` = egész;
  `10` = tízes; `100` = százas; `1000` = ezres. Más pozitív lépés, például `0,05`
  is használható, legfeljebb két tizedesjeggyel.
- **Kerekítés iránya**: legközelebbi (fél értéktől felfelé), felfelé vagy lefelé.
- **Kerekítés utáni korrekció**: hozzáadandó vagy levonandó összeg, például `-10`
  vagy `-0,05`. Alapértéke `0`.

Például 2162 kerekítése 1000-es lépéssel, legközelebbire, `-10` korrekcióval
1990-et ad. A 29,91 érték egészre felfelé kerekítve és `-0,05` korrekcióval 29,95.
Az alapbeállítás továbbra is 0,01-es lépés, legközelebbi, korrekció nélkül.
A kerekítés csak a képleteredményt érinti; közvetlen árlistaválasztásnál nem
módosítjuk a publikált forrásárat.

## Utolsó beállítások megjegyzése

A program **felhasználónként és árprojektenként, exportoszloponként** megjegyzi
a forrásválasztást, a képletet és a kerekítést a meglévő `admin_ui_preferences`
táblában. Új oldalmegnyitáskor és másik böngészőből is visszatöltődnek.
Más felhasználó vagy más árprojekt beállításait nem írják felül.

Az értékek a következő szerverkéréskor mentődnek: például forrásváltáskor,
kerekítési mező elhagyásakor, exportkor és a **Mégsem / ablakbezárás** kéréskor.
Az építőelemek hozzáadása önmagában nem indít minden kattintáskor szerverkérést.
A böngészőfül hirtelen bezárása előtt még el nem küldött módosítások nem
garantáltan mentődnek. A Mégsem az exportot szakítja meg, a személyes
exportbeállításokat nem vonja vissza.

A félbehagyott képlet is megmarad, de ettől még az export előtt minden
ellenőrzést elvégzünk. Közvetlen árlistára visszaváltás nem törli a korábbi
képletet. Új publikálás után az onnan hiányzó közvetlen árlista helyett a szokásos
alapválasztást ajánljuk; a képlet elavult hivatkozását az export hibával jelzi,
nem hagyja el csendben az építőelemet. A kezdő dátumot nem mentjük: mindig a mai
nap az alapérték.

## Adatforrás és eredmény

- Az árlisták és a termékárak az árprojekt **legutóbbi publikálásának mentett
  pillanatképéből** származnak, nem a későbbi piszkozatból vagy élő árlistatételekből.
- Az árfolyamok a publikálásban rögzített szezon aktuális, aktív árfolyamai.
  Ezeket a program exportonként egyszer olvassa be. Az árfolyamok nem részei a
  régi publikációk mentett árpillanatképének; későbbi árfolyammódosítás tehát
  befolyásolja a képletes exportot.
- A képlet az adott termék publikált áraival számol, normál műveleti sorrenddel.
  Nincs köztes kerekítés; a végén alkalmazzuk a megadott kerekítést és korrekciót.
  Az Excelbe két tizedesre reprezentálható szám kerül.
- Az Excelben **számérték**, nem futtatható Excel-képlet jelenik meg.
- A fix 30 oszlopos importstruktúra változatlan. Az **Eredeti / Lista ár** pár
  ugyanazt az értéket kapja; az outlet továbbra is egyoszlopos.
- Az export nem módosítja a kalkulációkat, a publikációt vagy az árlistatételeket.
- A személyes exportbeállítások megmaradnak a következő megnyitásra is.

## Ellenőrzések és jogosultság

Hibás zárójelezés, hiányzó műveleti jel, hiányzó publikált forrásár vagy árfolyam,
nullával osztás, illetve két tizedesre kerekítve nem pozitív vagy
99 999 999 999,99 feletti eredmény esetén **nem készül részleges export**.
A hiba az érintett képlet alatt jelenik meg, termékszintű hibánál a modellkóddal.
A képlet szerkeszthető marad.

Legfeljebb 128 építőelem és 32 szintű zárójelezés/előjel használható. A fix szám
legfeljebb 12 egész és 8 tizedesjegyű lehet, ponttal vagy vesszővel. A szerver
kizárólag típusos építőelemekből álló matematikai kifejezést értelmez; nem futtat
PHP-, JavaScript-, SQL- vagy Excel-kódot. Csak a publikációban szereplő árlistákra
és aktív pénznemekre lehet hivatkozni.

A meglévő projektmegtekintési és ERP-exportjog szükséges; nincs új jogosultság.

## Telepítés

Nincs új migráció, Composer-függőség, frontend build, kézi feltöltés vagy `.env`
módosítás. Szokásos Git-frissítés és Laravel cache-törlés/újraépítés szükséges.
A partnerportál és a publikált árak működése változatlan.

A kerekítés és megjegyzés módosításához, a `feature/pricing-module` worktree-ben:

```powershell
git add -- app/Filament/Support/Actions/PricingProjectLifecycleActions.php app/Filament/Support/Schemas/ErpPricingExportForm.php app/Services/Pricing/ErpExportPreferences.php app/Services/Pricing/ErpFormulaRounding.php app/Services/Pricing/ErpPricingExportService.php tests/Feature/ErpPricingFormulaExportTest.php docs/erp-pricing-formulas.md
git commit -m "Remember ERP export formulas and support per-column rounding"
git push origin feature/pricing-module
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Az éles WePanel/cPanel rendszerre csak külön jóváhagyott integráció után:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Új adatbázis-migráció, `composer install`, `npm run build`, manuális fájlfeltöltés
vagy további üzemeltetői beavatkozás nem szükséges.
