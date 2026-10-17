# Outlet árképzés

Az outlet bruttó kisker ár, azonos pénznemű normál kisker árból számítva:

`outlet = kisker végleges ár × (1 − minimum outlet kedvezmény % / 100)`, majd külön outlet kerekítés.

## Beállítás

1. **Árlisták**: új `Outlet (bruttó kisker)` típusú aktív árlista, például
   `OUTLET_HUF` vagy `OUTLET_CZK`. Add meg a pénznemet és az azonos pénznemű
   aktív **Forrás normál kisker árlista (outlet)** értékét.
2. **Árképzési paraméterek**: a nyitott árprojektben új **Outlet minimum
   kedvezmény %**, a megfelelő HUF/CZK pénznemmel. Például `25` = 25%.
   A megengedett tartomány 0-tól 100-nál kisebb értékig tart.
   Alapból globális, de a meglévő ország/főcsoport/modell szűkítés használható.
   Ha szükséges, adott outlet árlistára is szűkíthető.
3. **Árkerekítési szabályok**: `Outlet` ártípus, megfelelő árprojekt, pénznem
   és/vagy outlet árlista, a kívánt lépéssel és korrekcióval.
   A 2026.10.17-i fejlesztéstől az irány automatikus: először felfelé
   kerekítünk; ha a korrekció utáni ár túllépné a minimumkedvezményből
   adódó felső határt, a legmagasabb még megfelelő alsó lépcsőt használjuk.
   Outletnél a hagyományos irányválasztó nem használatos.
4. Számítsd ki a forrás kisker árakat, majd a **HUF kisker árak** vagy a
   **Devizás kisker árak → CZK** oldalon az **Outlet árak újraszámítása** gombot.

Nem hozunk létre automatikusan üzleti árlistát, kedvezményértéket vagy
kerekítési szabályt. Más támogatott devizához is ugyanígy konfigurálható.
Az outlet gomb és oszlopok csak konfigurált kedvezmény esetén jelennek meg
az adott árprojekt/pénznem kisker munkalapján, nem a nagyker/disztribútor oldalon.

## Táblázat és jóváhagyás

- Outlet árlistánként külön minimum és tényleges kedvezmény, kalkulált,
  manuális és végleges ár, felülvizsgálati jelzés és státusz látható.
- A manuális outlet ár a színezett cellán dupla kattintással módosítható.
  Üres bevitel törli a manuális árat; ez nem hagyja jóvá automatikusan.
- A minimumot el nem érő tényleges kedvezmény és végleges ár **piros**.
  Az ilyen outlet ár egyik jóváhagyási szinten sem fogadható el.
  Ez továbbra is érvényes a manuális/megtartott árakra. Az új kalkulált
  ár már automatikusan minimumkedvezmény-biztos kerekítéssel készül.
- A sor műveleti menüjében külön **Kisker ár jóváhagyása**, **Outlet ár
  jóváhagyása**, valamint **Minden ár jóváhagyása** szerepel. Mindháromhoz
  kijelöléses tömeges művelet is van. Egy termék kisker+outlet jóváhagyása
  együtt atomi: outlet hiba esetén a kisker jóváhagyása is visszagördül.
- Az outlet önálló ár, önálló jóváhagyással és megőrzött auditpillanatképpel.
  A sor részleteiben a kisker és az outlet számítási lépései is visszanézhetők.
- Forrásár, outlet kedvezmény vagy outlet kerekítés változása felülvizsgálatot
  kér. Az újraszámítás a jóváhagyott/megtartott/manuális árat nem írja felül.
  Lezárt projektekben és olvasási jogosultsággal nem lehet írni/jóváhagyni.

Az outlet kalkuláció és jóváhagyás **nem publikál** a `price_list_items`
táblába, így nem írja át a partnerportál mentett árait. A partnerárlista
export továbbra is kizárólag a mentett árlistatételekből dolgozik.

## Telepítés

Új migráció: `2026_10_16_140000_add_outlet_pricing.php`.
Új mezők: `price_lists.source_retail_price_list_id`,
`pricing_calculation_rows.outlet_discount_percent`. Meglévő üzleti adatot
nem írunk át. Nincs Composer- vagy Vite-függőségváltozás, kézi assetfeltöltés
nem kell. Jogosultság az eddigi kisker oldal és kalkulációs sor szabályai szerint.

Git (a pricing worktree-ben, csak a kapcsolódó fájlok hozzáadása után):

```bash
git add -- app database/migrations/2026_10_16_140000_add_outlet_pricing.php resources/views lang tests/Feature docs/outlet-pricing.md docs/partner-price-list-export.md
git commit -m "Add outlet pricing and flexible partner price list exports"
git push origin feature/pricing-module
```

NAS: a jóváhagyott `infrastructure/scripts/deploy-nas.ps1` helper intézi
a commitellenőrzést, pullt, migrációt és cache-frissítést.

Éles WePanel környezetben csak külön engedélyezett release után,
a megfelelő kiadási ágon/projektkönyvtárban:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Ehhez a változáshoz `composer install` és `npm run build` nem szükséges.
Kézi feladat: az outlet árlisták, kedvezményparaméterek és kerekítési szabályok
üzleti értékeinek beállítása. A fejlesztői ágat nem húzzuk közvetlenül élesre.
