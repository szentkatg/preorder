# Partnerportál: frissítési tájékoztató és Súgó

A frissítési tájékoztató a sikeres partneroldali azonosítás utáni teljes GET során,
a szezonválasztás előtt jelenik meg. A belépés utáni teljes újratöltés megmaradt
(friss session és CSRF-token). A névtelen belépőűrlap nem menthet felhasználói beállítást.

A „Ne jelenjen meg újra” csak a Tovább gombbal mentődik. A jelölés nélkül folytatva
a következő belépéskor ismét megjelenik az üzenet. A beállítás a
`partner_user_notice_dismissals` táblában, felhasználó és üzenetverzió szerint tárolódik.
Nem sütire vagy böngésző-tárolóra épül. Új tájékoztatóhoz a
`PartnerPortalNotice::KEY` módosítandó, így a régi elrejtés nem rejti el az új hírt.

A Súgó közvetlenül a nyelvválasztó mellett jelenik meg a belépésnél, a modalokban
és a rendelési fejlécekben. A külön felső Súgó-sáv ilyenkor nem jelenik meg;
nyelvválasztó nélküli oldalakon a közös elrendezés továbbra is biztosít hivatkozást.
Az élő nyelvváltást a `partner-interface-language-changed` esemény követi.

A `/partner/help/hu` és `/partner/help/en` külön lapon olvasható HTML-kézikönyv:
kattintható tartalomjegyzékkel, nagyítható képekkel és PDF-letöltő gombbal.
A `/partner/help/{hu|en}/pdf` a megfelelő PDF-et letöltésként szolgálja ki.
A kézikönyv saját nyelvváltója nem módosítja a portál munkamenetének nyelvét.
A képernyőképek optimált JPEG-ek a `public/help/partner-guide/{hu|en}` alatt;
betöltésük késleltetett, és kattintással teljes méretben megnyithatók.
A HTML-súgó nem használ külső CDN-t vagy Livewire-t.

Mindkét formátum névtelenül is elérhető, és nem függ a partnerportál ki/be
kapcsolásától. A tárhely könyvtárvédelme változatlanul érvényes. Más nyelv vagy
tetszőleges fájlnév nem engedélyezett. A leírások csak mintaadatokat tartalmaznak.

## Kézikönyvek

- `resources/help/partner-guide-hu.pdf` és `partner-guide-en.pdf`: teljes magyar
  és angol kézikönyv, a HTML-súgóval közös tartalomból, helyi mintaadatokkal
  készült képernyőképekkel. A korábbi magyar PDF-et nem fűzzük hozzá: így a
  jelenlegi működéssel ellentétes, régi útmutatás nem kerül vissza a fájlba.
- `resources/help/content/{hu|en}.json`: szerkeszthető tartalmi források.
- `scripts/build-partner-guide.py --updated 2026-11-27`: mindkét PDF előállítója
  a JSON-források és a publikált JPEG-ek alapján. A `--delivery` alapértelmezett
  célja `output/pdf`. A script nem kapcsolódik éles rendszerhez, és nem igényel
  korábbi kézikönyvet. ReportLab, Pillow, pypdf és Arial betűk szükségesek.
- `tests/Feature/Manual/PartnerGuideScreenshotsTest.php`: a jelenlegi Blade
  nézeteket rendereli SQLite memóriabeli mintaadatokkal. Csak a
  `BUILD_PARTNER_GUIDE_DEMO=1` kapcsolóval fut; `GUIDE_LOCALE=hu` és `en` külön
  futtatandó. A `.tmp/partner-manual-v2/tailwind.js` helyi stílusruntime szükséges.
- `scripts/render-partner-guide-demo.cjs`: a demó HTML-eket helyi, headless
  böngészővel képpé rendereli. Playwright és Edge (vagy `GUIDE_BROWSER`) kell
  hozzá. Külső HTTP-kérést nem enged, élő partnerfiókba nem lép be. A képeken
  a rögzített mintaállapot látható, nem élő Livewire-műveletek futnak.
- `scripts/prepare-partner-help-images.py`: a helyi, mintaadatokkal készült
  képernyőképeket optimalizálja a HTML-súgóhoz. Nem ér el élő rendszert.

Az eredeti tájékoztató bevezetéséhez migráció kellett; a kézikönyv mostani
frissítéséhez nem kell migráció. A két PDF a
verziózott kód része, nincs külön tárhelyre másolandó, figyelmen kívül hagyott fájl.
Ez a változtatás nem módosít rendelést, árlistát vagy partner-hozzáférést.

## 2026.11.27-i tartalmi frissítés

A leírások a két fület (Rendelés generálása/megnyitása, Rendelések listája),
a keresők külön hatókörét, az elérhető rendelések szerinti kezdőfület, a
rendelésből mindig listára visszatérést, valamint a fülváltáskor megmaradó
szűréseket és kijelöléseket ismertetik. Szerepel a tömeges beküldés külön
e-mail-választása és folyamatjelzése, a visszanyitó gomb nyelvválasztó előtti
helye, a hivatkozási szám fejlécbeli kijelzése és az események naplózása is.
