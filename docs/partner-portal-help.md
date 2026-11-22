# Partnerportál: frissítési tájékoztató és Súgó

A frissítési tájékoztató a sikeres partneroldali azonosítás utáni teljes GET során,
a szezonválasztás előtt jelenik meg. A belépés utáni teljes újratöltés megmaradt
(friss session és CSRF-token). A névtelen belépőűrlap nem menthet felhasználói beállítást.

A „Ne jelenjen meg újra” csak a Tovább gombbal mentődik. A jelölés nélkül folytatva
a következő belépéskor ismét megjelenik az üzenet. A beállítás a
`partner_user_notice_dismissals` táblában, felhasználó és üzenetverzió szerint tárolódik.
Nem sütire vagy böngésző-tárolóra épül. Új tájékoztatóhoz a
`PartnerPortalNotice::KEY` módosítandó, így a régi elrejtés nem rejti el az új hírt.

A közös partnerelrendezés és a belépési modalok Súgó hivatkozása magyar vagy angol
PDF-et nyit új lapon. Az élő nyelvváltást a `partner-interface-language-changed`
esemény frissíti a külső elrendezésben is. A karbantartási oldalról is elérhető.
A `/partner/help/hu` és `/partner/help/en` névtelenül is elérhető, nem függ a
partnerportál ki/be kapcsolásától; más nyelv vagy fájlnév nem engedélyezett.
A PDF-ek csak szemléltető mintaadatokat tartalmaznak.

## Kézikönyvek

- `resources/help/partner-guide-hu.pdf`: a korábban jóváhagyott, képernyőképes
  magyar kézikönyv és az aktuális frissítési/importálási kiegészítés.
- `resources/help/partner-guide-en.pdf`: angol változat, az alkalmazás angol
  felületéből, kizárólag helyi mintaadatokkal készített képekkel.
- `docs/partner-guide/en.json`: szerkeszthető angol tartalmi forrás.
- `scripts/build-partner-guide.py`: a PDF-ek előállítója. A korábbi magyar PDF
  és a mintaadatokkal készített angol képernyőképek megadandók argumentumként;
  a script nem kapcsolódik éles rendszerhez.

Telepítéskor a szokásos kódfrissítés mellett migráció szükséges. A két PDF a
verziózott kód része, nincs külön tárhelyre másolandó, figyelmen kívül hagyott fájl.
Ez a változtatás nem módosít rendelést, árlistát vagy partner-hozzáférést.
