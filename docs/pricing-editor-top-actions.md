# Termékenkénti árkezelő: felső gombsor és belső görgetés

A teljes képernyős modal korábban nem korlátozta a tartalom természetes
magasságát, ezért hosszú paraméterlista mellett a gombsor kikerülhetett a
látható területről, használható függőleges görgetés nélkül.

A közös szerkesztő most három sorra osztja a képernyőt:

1. Termék neve és bezárás.
2. **Minden ár mentése és jóváhagyása**, illetve **Mégse** – mindig láthatóan.
3. Külön függőlegesen görgethető tartalom, benne a paraméterekkel és árakkal.

A két táblázat saját vízszintes görgetése is megmarad. A módosítás csak a
`product-pricing-modal` jelölésű ablakot érinti, más modalok elrendezését nem.
A bekerülési, HUF/devizás kisker-, nagyker-, disztribútori, áttekintő és
általános kalkulációs listák, valamint a külön termékár-kezelő ugyanazt a
beállítást használják. A mentés továbbra is valamennyi függő árat jóváhagyja;
hibás előnézetnél a mentés letiltott. Az üzleti logika és jogosultságok nem változtak.

## Ellenőrzés

Kapcsolódó ellenőrzés: **57 teszt, 594 ellenőrzés, sikeres** (termékenkénti
árkezelő, kalkulációs gridműveletek és Livewire-válaszméret). Pint és
`git diff --check` is sikeres.

A tesztek a tényleges Filament-modalt renderelik a Livewire-kontextusban:
ellenőrzik a gombok jelenlétét és engedélyezését minden kiinduló listán,
a CSS felső gombsorát és görgetési korlátját, a mentést és a Mégsét.
Böngészős vizuális próba nem történt: nincs csatlakoztatott böngésző.

## Telepítés

Ez a javítás önmagában nem igényel migrációt, Composer installt, npm buildet,
kézi assetfeltöltést vagy `.env`-változtatást. A CSS közvetlenül a `public/css`
alatt van, a betöltés már eleve fájlidőbélyeges verziót használ. Frissítés után
a nyitott adminoldalt újra kell tölteni, de előtte a mentetlen bevitelekről
gondoskodni kell.

**A worktree-ben még kiadásra vár a korábbi termékkijelölési/anyagösszetétel-
fejlesztés is.** Együttes kiadásnál annak migrációja és ütközésellenőrzése
szükséges; lásd [pricing-project-products.md](pricing-project-products.md).
Annak fájllistáján felül e javítás fájljai:

```bash
git add -- app/Filament/Support/Concerns/HasProductPricingEditor.php public/css/admin-table-scroll.css tests/Feature/ProductPricingWorkspaceTest.php docs/pricing-editor-top-actions.md
git commit -m "Keep product pricing actions at the top and scroll modal content"
git push origin feature/pricing-module
```

NAS – csak külön kérésre, a teljes kiadás ellenőrzése után:

```powershell
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1 -CheckOnly
powershell.exe -NoProfile -File .\infrastructure\scripts\deploy-nas.ps1
```

Éles WePanel/cPanel – csak külön engedélyezett, éles ágba integrált release-nél:

```bash
git pull --ff-only
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan optimize
```

Ha a korábbi termékkijelölés is része a kiadásnak, a fenti rövid éles parancssor
helyett annak teljes, migrációt és előellenőrzést is tartalmazó eljárása szükséges.
