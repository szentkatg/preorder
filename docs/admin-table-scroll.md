# Listák vízszintes gördítősávja

A közös Filament admin táblázatkezelő minden listához külön gördítősávot hoz létre. A sáv a `.fi-ta-main` utolsó, normál elrendezésben helyet foglaló eleme, tehát a lapozás és az esetleges alsó szűrőpanel UTÁN következik. Nincs képernyőre rögzített, az utolsó sorra ráfedő sáv.

A táblázat adatainak maximális magassága az aktuális képernyőhöz igazodik, fenntartva a helyet a lapozónak és a gördítősávnak. Modális ablakban az ablak görgethető tartományát veszi alapul. A túl kicsi képernyőn legalább a fejléc és egy sor helyét meghagyja; ha ezek és a vezérlők együtt sem férnek el, az oldal/ablak saját függőleges görgetése szükséges, a sáv akkor sem fedi át a sorokat.

A külön sáv addig látható, amíg vízszintes túlnyúlás van. Nem tűnik el attól, hogy a lista alja a képernyőre kerül, és kis képernyőn sincs letiltva. Ha minden oszlop elfér, nincs szükség vízszintes gördítősávra. A sáv billentyűzettel is elérhető, és a táblázat görgetésével mindkét irányban szinkronizálódik. A függőleges natív gördítősáv megmarad; a vízszintes natív sáv helyett a lapozás alatti sáv használható.

Oszlopméretezés, oldalsáv-összecsukás, ablakméret-változás, szűrés, lapozás és Livewire-újrarajzolás után újraméretez. Az eltűnt táblázatokhoz tartozó sávokat eltávolítja; táblázatonként egy sávot tart fenn. A táblázat saját, tartalomfüggő magasságát nem kezeli újabb korlátozó nézetmagasságként, így nincs folyamatos zsugorodási ciklus.

Sima belső/külső függőleges görgetéskor NEM méretez újra. A régi külső `scroll`-figyelő minden képkockán az új képernyőpozícióból számolt magasságot, ezért a lista részben a görgetéssel ellentétesen nőtt, és lassította a lista aljáról a külső oldalra történő átmenetet. A sáv normál elrendezési elemként magától együtt mozog a listával; nincs szükség külső görgetésfigyelőre vagy mesterséges egérgörgő-átirányításra. Az adatlista natív függőleges scroll chaining beállítása `auto`.

## Termékűrlap alatti Színek lista

A termék létrehozása, szerkesztése és megtekintése kompakt űrlapot használ: kis képernyőn egy, közepes képernyőn két, széles képernyőn négy oszlopot, kisebb mezőközökkel. Az anyagösszetétel szövegterülete kezdetben egysoros; továbbra is több sor és hosszú szöveg menthető bele.

A termék Színek kapcsolt listáján a `data-product-colors-natural-scroll` jelző kikapcsolja a közös belső magasságkorlátot. A lista az aktuális lap összes sorához szükséges magasságot kapja, és az oldal görgetésével együtt mozog. Így a termékadatok kifuthatnak a képből, és a lista nem szorul a kezdeti képernyőpozícióján megmaradt egy-két sorba. A lapozás és az esetleg szükséges külön vízszintes gördítősáv megmarad. A többi listára nem vonatkozik ez a kivétel; új külső görgetésfigyelő nem került be.

## Ellenőrzés

- `tests/Browser/admin-table-scroll.mjs`: determinisztikus DOM-geometriás teszt a produkciós JS-en. Lapozó utáni hely, elférés, stabil magasság, kétirányú szinkron, rövid lista, átméretezés, Livewire-morph/újrarajzolás, több táblázat, eltávolítás, modális nézet és rejtett fülek. Ez nem valódi böngészős képernyőkép-ellenőrzés.
- `tests/Browser/admin-table-scroll-fixture.html`: helyben megnyitható, fiktív adatos vizuális tesztlap, a valódi közös CSS/JS használatával. Az „Utolsó sor” és az „Elrendezés ellenőrzése” gombbal a lapozó/sáv helye ellenőrizhető. Rövid/hosszú lista, szűrőpanel és újrarajzolás külön kipróbálható. Nem kapcsolódik adatbázishoz.
- Az admin jogosultsági és termékenkénti árkezelési regressziós tesztek ellenőrzik a közös assetek és az érintetlen modális elrendezés betöltését.
- `tests/Feature/Admin/ProductEditorLayoutTest.php`: létrehozó/szerkesztő/megtekintő űrlap sűrűsége, reszponzív oszlopszáma, valamennyi mező megőrzése; teljes anyagösszetétel és dátum mentése; kapcsolt színek szerkesztése, exportja, lapozása és a CSS-kivétel hatóköre. Ezek nem valódi böngészős görgetési vagy képernyőképes tesztek.

## Kiadás

Közvetlenül publikált, Gitben követett CSS/JS fájlok változtak, a meglévő render hook `filemtime()` verziózza az URL-jüket. Migráció, Composer install, npm build, kézi feltöltés, .env-módosítás és új jogosultság nem szükséges. NAS-frissítéskor a szokásos deploy script használható; az éles rendszer kiadása külön engedélyhez kötött. Nyitva maradt oldalon teljes frissítés (Ctrl+F5) szükséges az új globális eseménykezelő betöltéséhez.
