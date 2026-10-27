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
6. Ellenőrizd a szöveges képletet, majd indítsd az exportot.

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

## Adatforrás és eredmény

- Az árlisták és a termékárak az árprojekt **legutóbbi publikálásának mentett
  pillanatképéből** származnak, nem a későbbi piszkozatból vagy élő árlistatételekből.
- Az árfolyamok a publikálásban rögzített szezon aktuális, aktív árfolyamai.
  Ezeket a program exportonként egyszer olvassa be. Az árfolyamok nem részei a
  régi publikációk mentett árpillanatképének; későbbi árfolyammódosítás tehát
  befolyásolja a képletes exportot.
- A képlet az adott termék publikált áraival számol, normál műveleti sorrenddel.
  Nincs köztes kerekítés; a végeredmény két tizedesre, fél értéktől felfelé kerekül.
- Az Excelben **számérték**, nem futtatható Excel-képlet jelenik meg.
- A fix 30 oszlopos importstruktúra változatlan. Az **Eredeti / Lista ár** pár
  ugyanazt az értéket kapja; az outlet továbbra is egyoszlopos.
- Az export nem módosítja a kalkulációkat, a publikációt vagy az árlistatételeket.
- A képletek az aktuális exportablakhoz tartoznak; új exporthoz újra megadandók.

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
