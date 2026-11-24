# Állandó NAS- és tesztfrissítés

A szokásos, kódot és előre elkészített publikus fájlokat érintő frissítésekhez
mindig ugyanazt a belépési pontot használjuk, nem kiadásonként új `.tmp` szkriptet.
Az éles rendszer nem választható célpont. A parancs nem készít automatikus commitot.

## Parancsok

A `feature/pricing-module` worktree gyökerében, ellenőrzött és commitolt változásokkal:

```powershell
powershell.exe -NoProfile -File infrastructure/scripts/deploy-preorder.ps1 -Target Both -Mode Deploy -Publish
```

A `-Publish` feltölti ugyanazt a commitot a NAS és a teszt rögzített GitHub-ágára.
Nélküle a már feltöltött ágaknak kell a helyi HEAD-re mutatniuk. `-Target Nas`
vagy `-Target Staging` egyetlen környezetet választ ki.

Telepítés, mentés, maintenance és Git-feltöltés nélküli ellenőrzés:

```powershell
powershell.exe -NoProfile -File infrastructure/scripts/deploy-preorder.ps1 -Target Both -Mode Check
```

A Check a commitolt HEAD-et vizsgálja; a helyi nem commitolt változásokat nem
teszteli a szerveren. Eltérő szerververziónál előkészítési ellenőrzés történik,
azonos verziónál a belépőoldalak működését is ellenőrzi.

## Engedélyezés

A jóváhagyó felületen kizárólag az alábbi konkrét parancselőtaghoz érdemes tartós
engedélyt adni, ha a felület és a szervezeti szabályzat ezt megengedi:

```text
powershell.exe -NoProfile -File infrastructure/scripts/deploy-preorder.ps1
```

A munkakönyvtár mindig ez a worktree legyen. Ne adjunk általános `powershell.exe`,
`ssh`, tetszőleges szkript vagy teljes gépelérés engedélyt. A beállítás nem írja
felül a központi szabályokat; rendkívüli műveletnél további jóváhagyás maradhat.
A szkript módosítása továbbra is kódellenőrzést igényel.

## Biztonsági lépések

- Rögzített gépek, SSH-portok, hostkulcs-ellenőrzés, alkalmazásgyökerek,
  adatbázisok, repository és ágak ellenőrzése; csak fast-forward telepítés.
- Mindkét cél előellenőrzése még az első maintenance előtt.
- Környezetenként maintenance, privát tömörített teljes adatbázismentés és
  visszaolvasásos integritásellenőrzés, aztán telepítés és cache-újraépítés.
- A meglévő nem átmeneti táblák tartalmi lenyomatának, a nézeteknek, a `.env`
  és a könyvtárvédelem változatlanságának ellenőrzése, majd visszakapcsolás.
- NAS-on tényleges Nginx/FPM belépőoldalak; teszten alkalmazásszintű belépőoldalak
  és a publikus könyvtárvédelem 401 válaszának ellenőrzése.

Az adatbázismigráció, függőség-, frontendforrás-, konténer-, CI- vagy
környezetkonfiguráció-változás előtt a parancs megáll. Ezek külön ellenőrzött
előkészítést igényelnek; a script nem futtat önállóan migrációt vagy Composert.
Különleges kiadáshoz sem használunk automatikusan új nevű ideiglenes telepítőt.

A mentés helyét és az egyedi RunId-t a futás kiírja. A naplók és mentések
privátak: NAS-on `storage/app/private/release-audit`, teszten a release-mappák
melletti `.release-audit` könyvtárban. Nincs automatikus adatbázis-visszaállítás.

Ha a telepítés befejeződött, de az utóellenőrzés/visszakapcsolás szakadt meg,
ugyanazon helyi commit mellett, csak az érintett célra:

```powershell
powershell.exe -NoProfile -File infrastructure/scripts/deploy-preorder.ps1 -Target Staging -Mode Resume -RunId AZ_EREDETI_RUNID
```

A Resume kizárólag az adott RunId-hoz tartozó, már telepített commit ellenőrzését
és visszakapcsolását ismétli. Félbeszakadt telepítést vagy visszaállítást nem
talál ki önállóan. A siker feltétele a végső `PREORDER ... OK` eredmény.
