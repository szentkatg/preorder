# Árprojekt lezárása és ERP árexport

## Hol található?

Az **Árképzés → Árprojektek** listájában és az árprojekt megtekintő/
szerkesztőoldalán:

- **Lezárás és árak publikálása**
- **Árprojekt visszanyitása** (lezárt projektnél, külön jogosultsággal)
- **ERP árexport (Excel)** (az első sikeres publikálástól)

A státusz mezővel többé nem lehet lezárni vagy visszanyitni. A szezon
létrehozás után nem cserélhető. Lezárt projekt és kalkulációs/paraméter/
kerekítési rekordjai a generikus admin űrlapokon sem írhatók, törlésüket
a tömeges műveleteknél is korlátozzuk. Publikált projekt nem törölhető.

## Publikálás

A lezárás egy adatbázis-tranzakcióban:

1. Zárolja és ellenőrzi a projektet és szezont.
2. Ellenőrzi a projekt aktív termékeinek aktív árlistához tartozó kalkulációit:
   pozitív végleges ár, jóváhagyott/módosított jóváhagyott/2–3. körös
   jóváhagyott státusz, nincs felülvizsgálati jelzés, aktuális számítási
   források. Az „Átnézett” állapot még nem jóváhagyás. Outletnél a minimum
   kedvezmény is kötelező. Nem hagy jóvá semmit automatikusan.
3. Termék–szezon–árlista szerint létrehozza/frissíti a `price_list_items`
   rekordokat, a végleges árat a meglévő `net_price` mezőbe írva. Retail és
   outlet esetén ez a partnerportál eddigi adatmodellje szerint bruttó ár.
   Más szezonok, termékek és nem publikált árlistatételek nem változnak.
4. Megőrzi a publikálás időpontját, végrehajtóját, a termékneveket/kódokat,
   bekerülési értékeket, árlistákat, árakat, forrássorazonosítókat és a
   felülírt korábbi árakat a `pricing_project_publications.snapshot` mezőben.
5. Lezárja a projektet (`closed_at`, `closed_by`). Bármilyen hiba esetén
   az egész tranzakció visszagördül, nincs részleges publikálás.

Az árlistatétel modellszintű. Az azonos modell/szezon/árlista színenkénti
azonos árait egy tételbe vonjuk össze. Eltérő színárnál, vagy színenként
eltérő/hiányzó HUF bekerülési értéknél a rendszer hibát jelez, nem választ
önkényes árat. A bekerülési értéknek nem kell külön jóváhagyási státusz,
de legyen pozitív, egyértelmű és felülvizsgálatmentes.
Beszerzési forrásból kalkulált bekerülési értéknél a lezárás újraellenőrzi
az aktív legolcsóbb jelöltet, a beszerzési árat, pénznemet, árfolyamot,
szállítási költséget és vámot is. Eltérésnél előbb újraszámítás szükséges.

Csak a már kiszámított, aktív termék/aktív árlista árakat publikáljuk.
A funkció nem talál ki hiányzó árszinteket, és nem törli azokat a meglévő
végleges tételeket, amelyekhez most nincs publikált kalkuláció.

Visszanyitáskor a publikált árak megmaradnak. Az új munkapéldány csak az
ismételt lezáráskor kerül a partnerportál végleges áraiba, új publikálási
verzióval. A régi pillanatképek megmaradnak; a visszanyitó és időpontja is
naplózott. A lezárt projekt adatbázisbeli közvetlen státuszmódosítása sem
kerülheti meg a visszanyitási jogosultságot az Eloquent modellen keresztül.

## Jogosultságobjektum

A szerepkörök **Egyéb / egyedi jogosultságok** lapján külön
**Árprojekt életciklus** jogosultságok:

- `Publish:PricingProjectLifecycle`: lezárás és publikálás.
- `Reopen:PricingProjectLifecycle`: visszanyitás.
- `Export:PricingProjectLifecycle`: ERP árexport.

A migráció létrehozza a jogosultságokat, de meglévő szerepkörökhöz nem
rendeli hozzá automatikusan. A super_admin az eddigi szabály szerint
hozzáfér. Az adminban a projekt lista/megtekintési joga továbbra is kell.
Az általános `Update:PricingProject` önmagában nem ad visszanyitást,
publikálást vagy ERP-exportot. `shield:generate --all` nem szükséges,
a meglévő policykat ne generáljuk felül.

## Fix ERP Excel

Az „AW27 HTL TÁSKA ÁRAK 20260909.xlsx” minta pontos **30 oszlopos**
struktúrája, `Import` munkalap, három fejlécsor, első adat a 4. sorban.
Nincs hozzáadott cím, lábléc, rejtett üzleti oszlop vagy további munkalap.

| Oszlop | Tartalom |
| --- | --- |
| A | Szezon nélküli modellkód; csak a projekt szezonkódjával egyező végződést vágjuk le |
| B | Modell magyar neve (hiányában angol) |
| C–E | Üres szezon, megnevezés és a minta üres elválasztója |
| F | HUF bekerülési érték, 2 tizedesre matematikailag kerekítve |
| G–H | HUF disztribútor: Eredeti / Lista ár |
| I–J | HUF nagyker: Eredeti / Lista ár |
| K–L | HUF kisker: Eredeti / Lista ár |
| M | HUF outlet Lista ár |
| N–O | EUR disztribútor: Eredeti / Lista ár |
| P–Q | EUR nagyker: Eredeti / Lista ár |
| R–S | EUR kisker: Eredeti / Lista ár |
| T–U | CZK disztribútor: Eredeti / Lista ár |
| V–W | CZK kisker: Eredeti / Lista ár |
| X | CZK outlet Lista ár |
| Y–Z | RON kisker: Eredeti / Lista ár |
| AA–AB | RON nagyker: Eredeti / Lista ár |
| AC–AD | PLN kisker: Eredeti / Lista ár |

Az Eredeti/Lista ár párok azonos végleges árakat tartalmaznak. Nincs
leárazás-kezelés, átváltás vagy ÁFA-számítás. Nem létező árszint üres,
nem nulla. Az árak valódi Excel-számok, a modellkód/nevek szövegek,
nem keletkezhet belőlük képlet. Ütköző szezon nélküli modellkód hibát jelez.

A kezdő dátum a letöltés ablakában megadható (alapból aznap), az A2
mezőbe a minta szerinti `YYYY.MM.DD` szövegként kerül. Ha egy árszinthez
pontosan egy publikált árlista van, azt ajánljuk fel. Több azonos
típusú/pénznemű listánál választani kell; más pénznem/típus nem helyettesíti.

**Az export a legutóbbi publikálási pillanatképből készül.** Visszanyitott
projektben is a már kiadott verziót adja, nem a módosítás alatt álló
kalkulációt. Első publikálás előtt nem készül ERP-export.

## Telepítés

Migráció szükséges: `2026_10_16_160000_add_pricing_publications.php`.
Az előző outlet fejlesztés migrációja is szükséges, ha még nincs telepítve.
Composer/npm build/kézi assetfeltöltés nem kell. A migráció üres naplót és
nullable metaadatokat hoz létre, nem zár le és nem publikál projektet.

Git a pricing worktree-ben, a változtatások ellenőrzése után:

```bash
git add -- app config/filament-shield.php database/migrations/2026_10_16_140000_add_outlet_pricing.php database/migrations/2026_10_16_160000_add_pricing_publications.php resources/views lang tests/Feature docs
git commit -m "Add outlet pricing, project publication and fixed ERP export"
git push origin feature/pricing-module
```

NAS: `infrastructure/scripts/deploy-nas.ps1` (pull, migrate --force, cache).
Éles WePanel, csak külön jóváhagyott release után a kiadási ág könyvtárában:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Manuális teendő: megfelelő szerepkörök egyedi jogosultságainak beállítása,
majd árak jóváhagyása, a projekt lezárása, és ERP-export. A fejlesztői ágat
nem húzzuk közvetlenül élesre. A saját ERP-be történő tényleges importot
itt nem futtatjuk; az export adat- és struktúraegyezését tesztekkel ellenőrizzük.
