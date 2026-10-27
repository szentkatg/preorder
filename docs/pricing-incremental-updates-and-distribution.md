# Részleges árképzési frissítés és HUF kisker áreloszlás

## Kézi ár és beszerzési paraméterek

A kisker-, nagyker-, disztribútori és outlet kézi árak mentése csak a módosított
sor látható celláit frissíti Filament-partialokkal. A fejlécben lévő súlyozott
átlagok a teljes szűrt találati halmazból újraszámolódnak, nem csak a látható lapból.
A sor helye, a kijelölés, a szűrők és a kézzel beállított oszlopszélesség megmarad.
A következő szándékos rendezés, szűrés vagy lapozás ismét az aktuális adatokból
állítja össze a listát; szerkesztés közben nem mozgatjuk el a sort.

A látható oldal rekordazonosítói szerveroldalon zárolt Livewire-állapotban vannak;
teljes modellek és kalkulációs snapshotok nem kerülnek emiatt a kliensállapotba.
A cellák frissítése is a tényleges projekt/pénznem lekérdezését és a megtekintési
jogosultságot használja. A checkboxot tartalmazó cellákhoz nem nyúlunk.

Szállítás/vám módosításakor csak a kiválasztott paraméterszint érintett modelljei
kerülnek újraszámításra. Országos módosításnál az összes aktív beszerzési jelöltet
figyelembe vesszük, nem csak a korábban legolcsóbb ajánlat beszállítóját:
a paraméterváltozás a legolcsóbb jelölt személyét is megváltoztathatja.
A szűkebb paraméter által felülírt, ténylegesen változatlan sorokat nem mentjük újra.
A függő árak meglévő felülvizsgálati/jóváhagyási szabályai változatlanok.

Az outlet minimum- és tényleges kedvezmény, illetve kézi ár celláján súgó mutatja:
**aktuális végleges kisker ár × (1 − minimum kedvezmény / 100)**,
kerekítési szabály alkalmazása nélkül, a pénznem feltüntetésével.
Felülvizsgálandó outletnél a súgó jelzi, hogy ez tájékoztató célár.

## HUF kisker áreloszlás

Új, csak olvasható menüpont: **Árképzés → HUF kisker áreloszlás**.
A HUF kisker árak fejlécéből az **Áreloszlás főcsoportonként** gomb is ide visz.
Ugyanazt az árprojektválasztást használja; lezárt projektek az Archív megtekintésben érhetők el.
Hozzáféréséhez a HUF kisker oldal meglévő olvasási jogosultságai elegendők.

Oszlopok: Árprojekt, Árlista, Főcsoport kód, Főcsoport név,
**Végleges kisker HUF**, Modellek száma, Színállások száma.
Az árcsoport alapja a kézi/megtartott árat is tartalmazó végleges ár,
nem a képlettel eredetileg kalkulált ár. Csak pozitív HUF normál kisker sorok szerepelnek.

A modell azonos árkategóriában csak egyszer számít akkor is, ha több színszintű
kalkulációs sora van. A színállások száma az érintett modellek összes aktív színének
száma; nem az ársorok és nem az SKU-k száma. A nulla aktív színnel rendelkező
modell beleszámít a modellek közé, színállásszáma nulla.
Ha egy modell különböző színeinek eltér a végleges ára, a modell az egyes
érintett árkategóriákban külön-külön szerepel, mindegyiknél a modell aktív színeinek számával.

Árlistára és főcsoportra többválasztós szűrés van, az oszlopok rendezhetők és kereshetők.
Az Excel-export a teljes szűrt eredményt exportálja, nem csak a megjelenített lapot.
Az ár és a darabszámok valódi Excel-számok; az elválasztókat az Excel rendszerbeállítása adja.
Az összesítés lekérdezéskor készül, nincs hozzá új tábla vagy adattárolás.

## Ellenőrzés

Az árképzési regressziós futás 434 tesztje (3817 assertion) sikeres volt;
az összesítő célzott jogosultság-/export-/darabszámtesztjei külön is lefutottak.
A 100 soros teljesítmény-regresszióban a kézi ár mentése teljes táblázat-HTML
nélkül, 100 kB alatti válasszal működik. Ez helyi tesztadat, nem NAS-mérés.
Helyi headless Edge DOM-visszajátszásban ellenőriztük a cella-partialok
célzását, sorrend/kijelölés/oszlopszélesség megtartását, súgó- és átlagfrissítést.
A visszajátszás tesztadaptert használ a morph művelethez, nem teljes
bejelentkezett, hálózati Livewire E2E-teszt.

## Telepítés

Migráció, új jogosultság generálása, Composer install, npm build, kézi feltöltés
és .env-módosítás nem kell. A megfelelő célág Git-frissítése után a szokásos
artisan optimize:clear és artisan optimize szükséges.
Az éles rendszerre nem szabad a teljes fejlesztői ágat célzott kiadás nélkül áthúzni.

## Árprojekt-konfigurációs listák oszlopelrendezése

Az Árprojektek, Árképzési paraméterek és Árkerekítési szabályok listáin az oszlopfejléc jobb szélét húzva állítható a szélesség. Az oszlopválasztóban húzással módosítható a sorrend, illetve minden adatoszlop elrejthető/megjeleníthető. Sikeres alkalmazás után az oszlopválasztó bezáródik.

A szélességeket, sorrendet és láthatóságot felhasználónként és listánként külön az `admin_ui_preferences` tárolja. Oldalváltás, munkamenetváltás, szűrés és oszlopbe-/kikapcsolás nem törli a mentett szélességeket. Az „Oszlopszélességek alaphelyzetbe” csak a méretezést törli; az oszlopválasztó alaphelyzetbe állítása csak a sorrendet és láthatóságot.

A méretező JavaScript és CSS, valamint az elrendezést mentő PHP trait közös a meglévő kalkulációs oldalakkal. Nincs új migráció, Composer-függőség vagy Vite-build bemenet.

### Oszlopszélességek tartalomhoz igazítása

A közös gomb minden állítható oszlopszélességű árképzési listán elérhető, nem csak a három konfigurációs oldalon. Minden látható adatoszlopot a teljes szűrt találati halmaz megjelenített értékeihez igazít; a lapozás nem korlátozza a vizsgálatot. A fejléc, a szorzóátlag, az ikonok, a jelvények és a cellák belső térközei is beleszámítanak. A kliens a tényleges betűtípussal méri a szövegek szélességét, így nem pusztán a karakterek számából becsül.

A szerver 200 soros adagokban olvas, és oszloponként csak az egyedi, formázott szövegeket küldi el. Nem küld teljes modelleket, snapshotokat vagy új táblázat-HTML-t, és nem változtatja meg a sorok sorrendjét, a kijelölést, a szűrőket vagy az árakat. A láthatatlan oszlopok korábbi szélessége megmarad. A meglévő méretező 72–2000 képpontos korlátai érvényesek; a százalékos szerkeszthető mezők legalább a bevitelhez szükséges helyet megtartják. Üres listánál a fejléchez igazítunk.

Az eredmény listánként és felhasználónként mentődik. Ha igazítás közben másik projektet/pénznemet, szűrést vagy oszlopelrendezést választanak, a régi válasz nem írhatja át az új nézet szélességeit.
