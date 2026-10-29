# Rendelésválasztó: rendelésösszesítő szűrők

A partnerportál rendelésválasztó oldalán, a **Rendelések összesítése** blokkban két új szűrő van:

- **Rendelés státusza:** az elérhető rendelések státuszai. A régi `editing` státusz „Kitöltés alatt”, a `draft` „Piszkozat”, a `submitted` „Beküldve” néven jelenik meg.
- **Területi képviselő:** a rendelés partnerének `partners.sales_rep_erp_partner_code` mezője alapján. A választó az ERP-kódot és a hozzá tartozó partner nevét mutatja, nem a bejelentkező felhasználó nevét. Ha a kódhoz nincs partnerrekord, a kód önmagában választható.

Mindkét szűrő alapból minden rendelést enged át, együtt érvényesül a meglévő kereséssel és szűrőkkel, és a munkamenetben megmarad. A képviselő nélküli partnerek rendelései szűretlen állapotban szintén láthatók. A lehetőségek az összes hozzáférhető rendelésből származnak, nem szűkülnek le a már kiválasztott státuszra/képviselőre.

A képernyő összesítései, a „mindent kijelöl” és az Excel-export is a szűrt listát követi. Szűrőváltáskor a korábbi kijelölést töröljük. A szűrés a meglévő gyorsítótárazott összesítést használja, nem számolja újra a rendelési tételeket.

A gyorsítótár felhasználónként és felületi nyelvenként különül el; a sorformátum verziózott. A gyorsítótárból visszaolvasott rendelések hozzáférését ismét ellenőrizzük, ezért egy később elrejtett szezon vagy visszavont partner-hozzárendelés adatai és képviselői sem szivárognak át a listába vagy az exportba.

## Ellenőrzés és kiadás

A `tests/Feature/Partner/OrderSummaryFiltersTest.php` ellenőrzi a státuszokat, a partner szerinti képviselőszűrést, kombinált szűrést és összegeket, kijelölést, munkamenet-visszaállítást, Excel-exportot, mindhárom szerepkör hozzáférési korlátait, visszavont jogosultságot és HU/EN feliratokat. A szezonláthatósági és partnerhozzáférési regressziós tesztek is futtatandók.

Nincs új migráció, Composer-függőség, frontend build, kézi fájlfeltöltés, `.env`-módosítás vagy új jogosultság. A Blade-sablon meglévő stílusosztályokat használ. NAS-kiadáskor a szokásos `infrastructure/scripts/deploy-nas.ps1` használható a commit és push után. Az éles rendszer frissítése külön kiadási lépés, nem része a fejlesztésnek.
