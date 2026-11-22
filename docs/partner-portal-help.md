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

- `resources/help/partner-guide-hu.pdf`: a korábban jóváhagyott, képernyőképes
  magyar kézikönyv és az aktuális frissítési/importálási kiegészítés.
- `resources/help/partner-guide-en.pdf`: angol változat, az alkalmazás angol
  felületéből, kizárólag helyi mintaadatokkal készített képekkel.
- `resources/help/content/{hu|en}.json`: szerkeszthető tartalmi források.
- `scripts/build-partner-guide.py`: a PDF-ek előállítója. A korábbi magyar PDF
  és a mintaadatokkal készített angol képernyőképek megadandók argumentumként;
  a script nem kapcsolódik éles rendszerhez.
- `scripts/prepare-partner-help-images.py`: a helyi, mintaadatokkal készült
  képernyőképeket optimalizálja a HTML-súgóhoz. Nem ér el élő rendszert.

Telepítéskor a szokásos kódfrissítés mellett migráció szükséges. A két PDF a
verziózott kód része, nincs külön tárhelyre másolandó, figyelmen kívül hagyott fájl.
Ez a változtatás nem módosít rendelést, árlistát vagy partner-hozzáférést.
