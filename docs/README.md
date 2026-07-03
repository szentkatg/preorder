# Előrendelés B2B rendszer

## Leírás

Laravel 12 + Filament alapú B2B előrendelési rendszer ruházati és lábbeli nagykereskedelem számára.

A rendszer célja, hogy a partnerek online adhassák le szezonális előrendeléseiket méret-, szín- és modellbontásban, Excel import/export támogatással.

---

## Fő funkciók

- Partner portál
- Admin felület
- Előrendelések kezelése
- Katalógus alapú rendelés
- Mátrixos méretbevitel
- Excel export
- Excel import
- Több partner kezelése
- Több pénznem
- Több szezon

---

## Technológia

- Laravel 12
- PHP 8.3
- Filament 4
- Livewire 3
- MariaDB
- Docker (fejlesztői környezet)
- GitHub

---

## Repository felépítése

```
app/
bootstrap/
config/
database/
docker/
docs/
public/
resources/
routes/
scripts/
storage/
```

---

## Dokumentáció

| Dokumentum | Leírás |
|------------|--------|
| architecture.md | Rendszer architektúra |
| roadmap.md | Fejlesztési ütemterv |

---

## Fejlesztési alapelvek

- GitHub az elsődleges forráskód tár.
- Az éles rendszer csak tesztelt verziót kap.
- Minden nagyobb fejlesztés külön feature branch-en készül.
- A fejlesztés elsődleges környezete a Synology NAS Docker.