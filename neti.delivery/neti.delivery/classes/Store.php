<?php
namespace Neti\Delivery\Classes;


use \Bitrix\Catalog\StoreProductTable;
use \Bitrix\Catalog\StoreTable;
use \Bitrix\Main\Diag\Debug;

class Store
{
    public static $arStoreName = [
        MOSCOW_ENG => "Москва (100инг)",
        SAINT_PETERSBURG_ENG => "Санкт-Петербург (100инг)",
        EXPERT_ENG => "Экспертный (100инг)"
    ];

    public static $storeKeys = [
        MOSCOW_ENG,
        SAINT_PETERSBURG_ENG,
    ];

    /**
     * метод подготавливает информацию по остаткам товаров
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function prepareStoreBasketInfo($basket)
    {
        $storeBasketInfo = [];

        if ( $basket instanceof \Bitrix\Sale\Basket )
        {
            $arProduct = [];

            foreach ( $basket as $basketItem )
            {
                $productId = $basketItem->getField("PRODUCT_ID");

                $arProduct[$productId] = [
                    "ID" => $productId,
                    "QUANTITY" => (float)$basketItem->getField("QUANTITY"),
                ];
            }

            $storeBasketInfo = self::getStores();

            $storeProductDB = StoreProductTable::getList([
                "filter" => [
                    "PRODUCT_ID" => array_column($arProduct, "ID"),
                    "STORE.TITLE" => self::$arStoreName
                ],
                "select" => [
                    'AMOUNT',
                    'STORE_TITLE' => 'STORE.TITLE',
                    'I_PRODUCT_ID' => 'PRODUCT.IBLOCK_ELEMENT.ID',
                    'STORE_ADDRESS' => 'STORE.ADDRESS',
                    'STORE_DAY_DELIVERY' => 'STORE.UF_STORE_DAY_DELIVERY',
                    'STORE_ID'
                ]
            ])->fetchAll();

            foreach ( $storeProductDB as $storeProduct )
            {
                $keyStore = array_search($storeProduct["STORE_TITLE"], self::$arStoreName);
                $amount = (float)$storeProduct["AMOUNT"];

                if ( $keyStore )
                {
                    if ( $storeProduct["AMOUNT"] > 0 )
                    {
                        $storeBasketInfo[$keyStore]["ITEMS"][$storeProduct["I_PRODUCT_ID"]] = [
                            "ID" => $storeProduct["I_PRODUCT_ID"], //id элемента ИБ
                            "STORE_QUANTITY" => $storeProduct["AMOUNT"], //количество элемента на складе
                            "BASKET_QUANTITY" => $arProduct[$storeProduct["I_PRODUCT_ID"]]["QUANTITY"], //количество товара в корзине
                        ];
                    }

                    $storeBasketInfo[$keyStore]["TOTAL_AMOUNT"] += $amount;
                }
            }
        }

        return $storeBasketInfo;
    }

    public static function getStores()
    {
        $storeBasketInfo = [];
        $storeDb = StoreTable::getList([
            "filter" => ["TITLE" => self::$arStoreName],
            "limit" => count(self::$arStoreName),
            "select" => [
                "TITLE",
                "ADDRESS",
                "STORE_DAY_DELIVERY" => "UF_STORE_DAY_DELIVERY",
                "ID"
            ]
        ])->fetchAll();

        foreach ( $storeDb as $store )
        {
            $keyStore = array_search($store["TITLE"], self::$arStoreName);

            $storeBasketInfo[$keyStore] =
            [
                "ID" => $store["ID"],
                "TITLE" => $store["TITLE"], //название склада
                "ADDRESS" => $store["ADDRESS"], //адрес склада
                "STORE_DAY_DELIVERY" => (int)$store["STORE_DAY_DELIVERY"], //Количество дней для доставки со склада
                "CITY_ENG" => $keyStore, //название города в англ
                "BOXBERRY_PARCEL_CODE" => Shipment::$parcelBoxberryCode[$keyStore], //ближайший пункт приёма boxberry
                "ITEMS" => [],
            ];
        }

        return $storeBasketInfo;
    }
}