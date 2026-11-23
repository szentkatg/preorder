<?php

namespace App\Exports;

use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Empty, reusable input sheets. Examples belong only on the ignored guide sheet. */
class AdminImportTemplate implements WithMultipleSheets
{
    public const PRODUCTS = 'products';

    public const PARTNERS = 'partners';

    public const PRICE_LISTS = 'price_lists';

    public const SUPPLIERS = 'suppliers';

    public function __construct(public readonly string $type)
    {
        $this->definitions();
    }

    public function filename(): string
    {
        return match ($this->type) {
            self::PRODUCTS => 'termektorzs-import-minta.xlsx',
            self::PARTNERS => 'partner-import-minta.xlsx',
            self::PRICE_LISTS => 'arlista-import-minta.xlsx',
            self::SUPPLIERS => 'beszallito-beszerzesi-ar-import-minta.xlsx',
        };
    }

    public function sheets(): array
    {
        $sheets = [];
        $guide = [
            ['Általános', 'Használat', 'Az adatlapok üresek. A példák csak ezen az útmutató lapon szerepelnek, nem importálódnak.'],
            ['Általános', 'Munkalapok és fejlécek', 'A munkalapok nevét és az első sor oszlopfejléceit ne nevezd át. Az oszlopok sorrendje változtatható: az import fejléc alapján azonosít.'],
            ['Általános', 'Kódok', 'A példakódok helyett a rendszerben használt kódokat add meg. A kódoszlopok szövegesek, hogy a vezető nullák megmaradjanak.'],
            ['Általános', 'Ellenőrzés', 'Először validáld a kitöltött fájlt. Az import meglévő adatokat is felülírhat. Az üres mező nem minden importnál jelenti a régi érték megtartását.'],
        ];

        foreach ($this->definitions() as $name => $definition) {
            $sheets[] = new ImportTemplateSheet($name, array_keys($definition['columns']));
            $guide[] = [$name, 'Munkalap', $definition['hint']];
            foreach ($definition['columns'] as $column => $hint) {
                $guide[] = [$name, $column, $hint];
            }
        }
        $sheets[] = new ImportTemplateSheet('Kitöltési útmutató', ['Munkalap', 'Oszlop', 'Kitöltés, példa'], $guide, guide: true);

        return $sheets;
    }

    private function definitions(): array
    {
        return match ($this->type) {
            self::PRODUCTS => [
                'products' => ['hint' => 'Kötelező munkalap. Egy sor egy modell. A termékek törzsadatait hozza létre vagy frissíti.', 'columns' => [
                    'model_code' => 'Egyedi modellkód, pl. B03028W27. A kapcsolódó lapokon ugyanezt használd.',
                    'visible_to_partners' => '1 = partnerportálon látható, 0 = rejtett. Az aktív állapottól független. Hiányzó oszlop vagy üres cella: meglévő terméknél a beállítás megmarad, új terméknél rejtett. Árazáshoz active=1 és visible_to_partners=0 használható.',
                    'season_code' => 'Létező szezonkód, pl. W27.',
                    'brand_code' => 'Létező márkakód.',
                    'order_sheet_type_code' => 'Létező rendelőlap-típuskód, pl. T.',
                    'item_main_group_code' => 'Létező főcsoportkód, pl. B03.',
                    'size_range_code' => 'Létező méretsorkód; a mező üresen is hagyható.',
                    'name_hu' => 'A modell neve, pl. EMIRO. Külön angol terméknév nem szükséges.',
                    'material_composition' => 'Anyagösszetétel, pl. 100% poliészter. Opcionális mező.',
                    'promised_delivery_date' => 'Ígért szállítási határidő. Valódi Excel-dátum, 2027-03-01 vagy 2027.03.01 is használható. Üresen törli a dátumot.',
                    'catalog_group_name_hu' => 'Katalóguscsoport neve, pl. SIDE BAGS.',
                    'catalog_group_sort' => 'A katalóguscsoport sorrendje, egész szám.',
                    'catalog_sort' => 'A modell katalógussorrendje, egész szám.',
                    'catalog_page' => 'Katalógusoldal, egész szám.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'colors' => ['hint' => 'Kötelező munkalap. Egy sor egy modell egy színállása.', 'columns' => [
                    'model_code' => 'Az ugyanebben a fájlban, a products lapon megadott modellkód.',
                    'color_code' => 'Legfeljebb kétkarakteres színkód, pl. 01. Szövegként add meg.',
                    'name_hu' => 'Színnév, pl. NAVY. Külön angol név nem szükséges.',
                    'sort_order' => 'Színsorrend, egész szám.',
                    'image_url' => 'Teljes kép-URL vagy color-images/ kezdetű útvonal. Üresen nem hoz létre képet.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'skus' => ['hint' => 'Kötelező munkalap. Egy sor egy modell–szín–méret SKU, illetve gyűjtő SKU.', 'columns' => [
                    'model_code' => 'A kapcsolódó modell kódja.',
                    'color_code' => 'Az ugyanebben a fájlban, a colors lapon megadott színkód.',
                    'size_code' => 'Létező méretkód. Gyűjtő SKU-nál üres lehet.',
                    'sku_code' => 'Egyedi SKU-cikkszám. Szövegként add meg.',
                    'sku_name' => 'Az SKU megnevezése.',
                    'type' => 'normal = darabos SKU, assortment = gyűjtő SKU.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'assortments' => ['hint' => 'Opcionális munkalap, üresen hagyható. Egy sor a gyűjtő egy összetevője.', 'columns' => [
                    'model_code' => 'A gyűjtő modellkódja.',
                    'color_code' => 'A gyűjtő színkódja.',
                    'assortment_sku_code' => 'A gyűjtő SKU-cikkszáma az ugyanebben a fájlban található skus lapról.',
                    'component_sku_code' => 'A darabos SKU cikkszáma az ugyanebben a fájlban található skus lapról.',
                    'quantity' => 'Az összetevő darabszáma egy gyűjtőn belül, pozitív egész szám.',
                ]],
            ],
            self::PARTNERS => [
                'partners' => ['hint' => 'Kötelező munkalap. Egy sor egy partner.', 'columns' => [
                    'erp_partner_code' => 'Egyedi ERP-partnerkód, pl. P000123.',
                    'name' => 'Partner neve.',
                    'email' => 'Partner e-mail-címe. Opcionális.',
                    'phone' => 'Partner telefonszáma. Opcionális.',
                    'sales_rep_erp_partner_code' => 'Területi képviselő ERP-partnerkódja. Üres érték törli a meglévő hozzárendelést.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'partner_addresses' => ['hint' => 'Kötelező munkalap. Egy sor egy partnercím.', 'columns' => [
                    'erp_partner_code' => 'A partners lapon vagy a törzsben szereplő partner kódja.',
                    'addrid' => 'Egyedi ERP-címkód. Szövegként add meg.',
                    'name' => 'Cím vagy bolt neve.',
                    'country' => 'Ország, pl. HU.',
                    'zip' => 'Irányítószám; szövegként, a vezető nullák megőrzésével.',
                    'city' => 'Település.',
                    'street' => 'Utca és házszám.',
                    'contact_name' => 'Kapcsolattartó neve.',
                    'email' => 'A cím e-mail-címe.',
                    'phone' => 'A cím telefonszáma.',
                    'language_code' => 'Létező nyelvkód, pl. HU vagy EN.',
                    'currency_code' => 'Létező pénznemkód, pl. HUF vagy EUR.',
                    'price_list_code' => 'Létező árlistakód, pl. Wholesale_HU.',
                    'allow_assortment_ordering' => '1 = gyűjtős rendelés engedélyezett, 0 = nem engedélyezett.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'partner_users' => ['hint' => 'Kötelező munkalap, de üresen hagyható, ha nem importálsz felhasználókat. Az e-mail azonosítja a felhasználót.', 'columns' => [
                    'erp_partner_code' => 'A partner kódja.',
                    'name' => 'Felhasználó neve.',
                    'email' => 'Felhasználó egyedi e-mail-címe.',
                    'role' => 'partner_admin, address_user vagy sales_rep. Címjogosultságokat az adminban külön kell beállítani.',
                    'password' => 'Új felhasználónál adj meg egyedi jelszót. Meglévőnél üresen hagyva megmarad a jelszó. A fájlt kezeld bizalmasan.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'address_brands' => ['hint' => 'Kötelező munkalap, üresen hagyható. Egy sor egy címhez tartozó márka.', 'columns' => [
                    'erp_partner_code' => 'A cím partnerének ERP-kódja.',
                    'addrid' => 'A cím ERP-kódja.',
                    'brand_code' => 'Létező márkakód.',
                ]],
                'address_order_sheet_types' => ['hint' => 'Kötelező munkalap, üresen hagyható. Rendelőlap-típusokat rendel a címhez, nem rendeléstípusokat.', 'columns' => [
                    'erp_partner_code' => 'A cím partnerének ERP-kódja.',
                    'addrid' => 'A cím ERP-kódja.',
                    'order_sheet_type_code' => 'Létező rendelőlap-típuskód, pl. T, S vagy B.',
                ]],
            ],
            self::PRICE_LISTS => [
                'price_lists' => ['hint' => 'Kötelező munkalap. Egy sor egy árlistafej. A szezon a tételekhez tartozik, nem az árlistafejhez.', 'columns' => [
                    'code' => 'Egyedi árlistakód, pl. Retail_HU.',
                    'name' => 'Árlista alapneve. Fordításai külön a Fordítások menüben kezelhetők.',
                    'type' => 'retail, wholesale, distributor vagy outlet.',
                    'currency_code' => 'Létező pénznemkód, pl. HUF vagy EUR.',
                    'retail_price_list_code' => 'Nagyker/disztribútor árlistához kapcsolt kisker árlista kódja. Üres érték törli a kapcsolatot.',
                    'source_wholesale_price_list_code' => 'Disztribútor árlista nagyker forrásának kódja. Üres érték törli a kapcsolatot.',
                    'active' => '1 = aktív, 0 = inaktív.',
                ]],
                'price_list_items' => ['hint' => 'Kötelező munkalap. Egy sor egy árlista–szezon–modell ára.', 'columns' => [
                    'price_list_code' => 'A price_lists lapon vagy a törzsben szereplő árlista kódja.',
                    'season_code' => 'Létező szezonkód, pl. W27.',
                    'model_code' => 'Létező modellkód.',
                    'net_price' => 'Numerikus ár az árlista pénznemében. A mező neve kisker/outlet esetén is net_price, de ott bruttó kiskerárat tárolunk.',
                ]],
            ],
            self::SUPPLIERS => [
                'suppliers' => ['hint' => 'Legalább ez vagy a product_purchase_prices lap szükséges. Egy sor egy beszállító.', 'columns' => [
                    'erp_partner_code' => 'Egyedi ERP-beszállítókód.',
                    'name' => 'Beszállító neve.',
                    'short_name' => 'Rövid név. A fejléc szükséges, az érték lehet üres.',
                    'addrid' => 'ERP-címkód. Opcionális.',
                    'country_code' => 'Kétbetűs országkód, pl. CN vagy HU. Üresen törli a korábbi országot.',
                    'active' => '1 = aktív, 0 = inaktív. Üresen a meglévő állapot megmarad.',
                ]],
                'product_purchase_prices' => ['hint' => 'Legalább ez vagy a suppliers lap szükséges. Egy sor egy modell/szín és beszállító beszerzési ára.', 'columns' => [
                    'model_code' => 'Létező modellkód.',
                    'color_code' => 'A modell színkódja. Üresen modell szintű beszerzési árat jelent.',
                    'supplier_code' => 'ERP-beszállítókód a suppliers lapról vagy a beszállítótörzsből.',
                    'currency_code' => 'Létező pénznemkód, pl. USD.',
                    'purchase_price' => 'Nem negatív numerikus beszerzési ár, pl. 8,8.',
                    'active' => '1 = aktív, 0 = inaktív. Üresen a meglévő állapot megmarad.',
                ]],
            ],
            default => throw new InvalidArgumentException('Ismeretlen importmintatípus.'),
        };
    }
}
