# Fordítási architektúra

## Cél

A projektben két külön fordítási rendszer működik, eltérő feladatokra.

### Laravel `lang` fájlok

A `lang` mappa továbbra is az alkalmazás felületi szövegeinek fordítására szolgál.

Ide tartoznak például:

- menüpontok;
- gombfeliratok;
- validációs üzenetek;
- hibaüzenetek;
- Blade és Filament feliratok;
- általános alkalmazásszövegek.

A Laravel beépített fordítási rendszerét a `translations` adatbázistábla nem váltja ki.

### `translations` adatbázistábla

A `translations` tábla az üzleti törzsadatok és entitások fordítására szolgál.

Ide kerülhetnek például:

- rendeléstípusok nevei;
- partnerkategóriák nevei;
- márkák nevei;
- pénznemek nevei;
- szezonok nevei;
- színek nevei;
- termékek nevei és más fordítható üzleti mezők.

## Kapcsolási elv

A fordítások nem adatbázis-rekordazonosítóhoz kapcsolódnak.

A kapcsolat alapja:

- `entity`
- `entity_code`
- `field`
- `language_id`

Példa:

| entity | entity_code | field | language | value |
|---|---|---|---|---|
| order_type | VRELO | name | HU | Normál előrendelés |
| order_type | VRELO | name | EN | Standard preorder |
| partner_category | WHOLESALE | name | HU | Nagykereskedelem |

Az `entity_code` az adott törzsadat stabil üzleti kódja.

## Tábla felépítése

A `translations` tábla mezői:

- `translation_id`
- `entity`
- `entity_code`
- `field`
- `language_id`
- `value`
- `created_at`
- `updated_at`

Az alábbi mezők együtt egyediek:

```text
entity
entity_code
field
language_id