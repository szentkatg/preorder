# Főcsoportkód-mintás árképzési paraméterek

A konkrét főcsoportok a paraméterűrlapon és a listában kóddal és névvel
jelennek meg, például `S05 - cipő`. A választóban és a lista főcsoportos
keresőjében a kódra és a névre is lehet keresni. A kalkulációból megnyitott
szűkítésválasztó is kóddal és névvel mutatja a konkrét főcsoportot.

## Jokeres szabály felvitele

Az Árképzési paraméterek űrlapján válaszd a **Főcsoportkód-minta (joker)**
szűkítést, és add meg a mintát:

- `S*`: minden S-sel kezdődő főcsoportkód.
- `S0?`: S0-val kezdődő, összesen három karakteres kódok.
- `*`: minden főcsoportkód (főcsoport nélküli termékre nem illeszkedik).

A `*` tetszőleges hosszúságú, a `?` egyetlen karaktert helyettesít. A többi
megengedett karakter szó szerint értendő (például az aláhúzás nem joker).
A kis- és nagybetű nem számít; mentéskor nagybetűsítünk, az ismételt
csillagokat összevonjuk. A mező legfeljebb 100 karakteres; belső szóköz
és joker nélküli minta nem adható meg. Konkrét kódhoz használd a
**Főcsoport** szűkítést.

## Elsőbbség és ütközések

A legerősebbtől a leggyengébbig: modell → anyagösszetétel → konkrét
főcsoport → főcsoportkód-minta → beszállítói ország → globális.
Például az S05-re megadott konkrét vámparaméter megelőzi az S* szabályt.
Az árlista- és pénznemszűkítések azonos szűkítési szinten az eddigi módon
érvényesülnek.

Azonos árlista-/pénznemspecificitás mellett több illeszkedő mintából a több
konkrét karaktert, majd több `?`-t, majd kevesebb `*`-ot tartalmazó minta
nyer. Például az `S0*` erősebb az `S*`-nál. Egyenrangú minták közös,
létező főcsoporttal nem menthetők aktívként. A másolás ugyanilyen
ellenőrzéssel, tranzakcióban történik; ha bármely sor nem menthető, az egész
másolás visszagördül. Az azonos mintákra a meglévő felülírás/kihagyás
választás vonatkozik. Ha új főcsoport felvitele vagy közvetlen SQL-módosítás
később hoz létre egyenrangú átfedést, a kalkuláció hibát jelez, nem választ
önkényesen. A konkrét főcsoportos szabály ilyenkor is megelőzi a mintákat.

A jokeres paraméter minden közös paraméterfeloldást használó árszinten
alkalmazható. A szűkített bekerülésiérték-újraszámítás és az outlet
felülvizsgálati jelölése a mintának megfelelő termékekre korlátozódik.
A kalkulációs árak paraméter-pillanatképe a mintát is megőrzi.

Az új `pricing_parameters.item_main_group_pattern` mezőt migráció hozza
létre; a meglévő paraméterek, árlistatételek és partneroldali működés nem
módosulnak. Az új mező régi sorokban NULL.
