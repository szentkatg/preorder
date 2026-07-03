# Architektúra

## Cél

A jelenlegi működő előrendelési rendszer hosszú távon karbantartható,
bővíthető és jól skálázható architektúrává alakítása.

---

# Környezetek

## Production

- Tárhely
- Stabil verzió
- Csak kiadott verziók

## Development

- Synology NAS
- Docker
- GitHub Feature Branch

## GitHub

A GitHub a projekt központi forrása.

---

# Technológia

- Laravel 12
- PHP 8.3
- Filament 4
- Livewire 3
- MariaDB
- Redis
- Mailpit
- Nginx
- Docker Compose

---

# Fő modulok

## Admin

- törzsadatok
- import
- export
- felhasználók
- jogosultságok

## Partner

- rendelések
- katalógus
- excel import
- excel export

---

# Fejlesztési célok

## Jogosultságok

A jelenlegi role alapú rendszer helyett:

- Role
- Permission
- User Access

szétválasztása.

A funkció jogosultságok és az adat hozzáférések külön kezelése.

---

## Többnyelvűség

A jelenlegi

name_hu
name_en

mezők helyett

Translation rendszer.

---

## Teljesítmény

Kiemelt cél:

- Livewire optimalizálás
- kisebb válaszméret
- gyorsabb render
- cache használata

---

## Docker

A fejlesztői környezet teljes egészében Docker Compose alapú lesz.

Szolgáltatások:

- PHP
- MariaDB
- Nginx
- Redis
- Mailpit
- phpMyAdmin