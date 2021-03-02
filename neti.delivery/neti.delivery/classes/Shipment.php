<?php
namespace Neti\Delivery\Classes;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\SystemException;
use \Neti\Delivery\Classes\Store;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Sale\Delivery\Services\Manager;
use \Bitrix\Sale;

Loc::loadMessages(__FILE__);

class Shipment
{
    const ONE_DAY_SHIPMENT = 1;
    const PLUS_DAY = 2;
    /**
     * коды ближайших пунктов доставки BoxBerry
     * @var string[]
     */
    public static $parcelBoxberryCode = [
        MOSCOW_ENG => "19796",
        SAINT_PETERSBURG_ENG => "04854",
    ];

    /**
     * метод рассчитывает город для отгрузки
     * @param $locationInfo
     */
    public static function getShipmentCity($locationInfo, $basket)
    {
        if ($basket instanceof \Bitrix\Sale\Basket)
        {
            $storeBasketInfo = Store::prepareStoreBasketInfo($basket);

            $spbStore = $storeBasketInfo[SAINT_PETERSBURG_ENG];
            $mskStore = $storeBasketInfo[MOSCOW_ENG];
            $expertStore = $storeBasketInfo["EXPERT"];

            if ( count($spbStore["ITEMS"]) == 0 && count($mskStore["ITEMS"]) == 0 && count($expertStore["ITEMS"]) == 0 ) return false;

            //флаг, определяющий находится ли текущий город в северо-западном округе
            $isNorthWestDistrict = (ToLower($locationInfo["DISTRICT"]["I_NAME"]) == Loc::getMessage("LOWER_DISTRICT"));

            if ( $isNorthWestDistrict === true )
            {
                /* если город в северо-западе - сначала идёт проверка по кол-ву товара на складе СПБ и экспертном */
                list($shipment_time_day, $cityShipment, $addressShipment, $pointCode) = self::spbCalculate($spbStore, $mskStore, $expertStore);
            }
            /* если город не в северо-западе - идёт проверка по кол-ву товара на складе МСК */
            else
            {
                list($shipment_time_day, $cityShipment, $addressShipment, $pointCode) = self::mskCalculate(
                    $mskStore,
                    false,
                    [
                        $spbStore,
                        $expertStore
                    ]
                );
            }

            if ( $shipment_time_day !== false && is_numeric($shipment_time_day) )
            {
                $shipment_time_day += self::PLUS_DAY;
            }

            return
            [
                "SHIPMENT_TIME_DAY" => $shipment_time_day, //срок отгрузки в днях
                "CITY" => $cityShipment, //название города отгрузки
                "ADDRESS" => $addressShipment, //адрес склада отгрузки
                "POINT_CODE" => $pointCode,
            ];
        }

        return false;
    }

    private static function spbCalculate($spbStore, $mskStore, $expertStore)
    {
        $spbTotalAmount = $spbStore["TOTAL_AMOUNT"];
        $expertTotalAmount = $expertStore["TOTAL_AMOUNT"];
        $spbCity = Loc::getMessage("SAINT_PETERSBURG_CITY");

        $sumTotalAmount = $spbTotalAmount + $expertTotalAmount;

        $cityShipment = $spbCity;
        $pointCode = self::$parcelBoxberryCode[SAINT_PETERSBURG_ENG];
        $addressShipment = $spbStore["ADDRESS"];

        if ( !$spbStore["ITEMS"] ) $spbStore["ITEMS"] = [];
        if ( !$expertStore["ITEMS"] ) $expertStore["ITEMS"] = [];

        $spbExpertItems = $spbStore["ITEMS"] + $expertStore["ITEMS"];
        $tmpStore["ITEMS"] = $spbExpertItems;

        if ( count($mskStore["ITEMS"]) > 0 || count($expertStore["ITEMS"]) > 0 )
        {
            $itemsDiff = self::getProductIdDiff([
                 "STORES" => array_merge([$spbStore], [$mskStore]),
                 "MAIN_STORE" => $tmpStore
            ]);

             $isEnoughAmountOnStore = ( count($itemsDiff) == 0 );

             if ( $isEnoughAmountOnStore )
                 $isEnoughAmountOnStore = self::isEnoughAmount($tmpStore["ITEMS"], $sumTotalAmount);
        }
        else
        {
            /* кол-во товара на складе СПБ + экспертный достаточно? */
            $isEnoughAmountOnStore = self::isEnoughAmount($tmpStore["ITEMS"], $sumTotalAmount);
        }

        if ( $isEnoughAmountOnStore === true )
        {
            /* достаточно ли остатка на складе СПБ? */
            $isEnoughAmountOnStore = self::isEnoughAmount($spbStore["ITEMS"]);

            /* заказ оформляем с СПБ, доставка рассчитывается со склада СПБ, срок отгрузки 1 день */
            if ($isEnoughAmountOnStore === true)
            {
                $shipment_time_day = self::ONE_DAY_SHIPMENT;
            }
            /* иначе заказ оформляется с СПБ, доставка рассчитывается со склада СПБ, срок отгрузки "поле с админки" */
            else
            {
                $shipment_time_day = $spbStore["STORE_DAY_DELIVERY"];
            }
        }
        else
        {
            /* иначе переключаемся на Мск */
            list($shipment_time_day, $cityShipment, $addressShipment, $pointCode) = self::mskCalculate(
                $mskStore,
                true,
                [
                    $spbStore,
                    $expertStore
                ]
            );
        }

        return [
            $shipment_time_day,
            $cityShipment,
            $addressShipment,
            $pointCode
        ];
    }

    private static function getProductIdDiff($arData)
    {
        $arProductId = [];
        $mainStoreProductId = array_keys($arData["MAIN_STORE"]["ITEMS"]);
        $arDiff = [];

        foreach ( $arData["STORES"] as $store )
        {
            if ( !$store["ITEMS"] ) continue;

            foreach ( $store["ITEMS"] as $productId => $item )
            {
                if ( !in_array($productId, $mainStoreProductId) )
                {
                    $arDiff[] = $productId;
                }
            }
        }

        return $arDiff;
    }

    private static function mskCalculate($mskStore, $isNorthWestDistrict = false, $anotherStores = [])
    {
        $cityShipment = Loc::getMessage("MOSCOW_CITY");
        $pointCode = self::$parcelBoxberryCode[MOSCOW_ENG];
        $addressShipment = $mskStore["ADDRESS"];

        /* если несколько складов, нужно проверить что все товары корзины есть на складе мск */
        if ( count(array_column($anotherStores, "ITEMS")) > 0 )
        {
            $itemsDiff = self::getProductIdDiff([
                "STORES" => array_merge([$mskStore], $anotherStores),
                "MAIN_STORE" => $mskStore,
            ]);

            $isEnoughAmountOnStore = ( count($itemsDiff) == 0 );

            if ( $isEnoughAmountOnStore )
                $isEnoughAmountOnStore = self::isEnoughAmount($mskStore["ITEMS"]);
        }
        else
        {
            $isEnoughAmountOnStore = self::isEnoughAmount($mskStore["ITEMS"]);
        }

        if ( $isEnoughAmountOnStore === true )
        {
            /* если достаточно остатка - заказ оформляем с МСК, доставка рассчитывается со склада в МСК, срок отгрузки 1 день */
            $shipment_time_day = self::ONE_DAY_SHIPMENT;
        }
        /* если область северо-запад и на складе мск не достаточно товара, разделяем заказ */
        elseif ( $isNorthWestDistrict === true )
        {
            /* разбиваем на 2 заказа, первый заказ - отгрузка с МСК, второй - с СПБ */
            $shipment_time_day = false;

            if ( self::getTotalStoreWithItems(array_merge([$mskStore], $anotherStores)) < 2 )
            {
                throw new SystemException(Loc::getMessage("CALCULATE_ERROR"));
            }
        }
        else
        {
            /* иначе, заказ оформляется с МСК, доставка рассчитывается со склада в МСК, срок отгрузки "поле с админки" + 2 дня */
            $shipment_time_day = $mskStore["STORE_DAY_DELIVERY"] + 2;
        }

        return [
            $shipment_time_day,
            $cityShipment,
            $addressShipment,
            $pointCode
        ];
    }

    private static function getTotalStoreWithItems($arStores)
    {
        $totalStoreWithItems = 0;

        foreach ( $arStores as $store )
        {
            if ( count($store["ITEMS"]) > 0 )
                $totalStoreWithItems++;
        }

        return $totalStoreWithItems;
    }

    private static function isEnoughAmount($storeItems, $totalAmount = 0)
    {
        $isEnoughAmountOnStore = true;

        if ( count($storeItems) == 0 )
            $isEnoughAmountOnStore = false;
        else
        {
            foreach ( $storeItems as $storeItem )
            {
                /* проверяем каждый товар на складе если не указана общее кол-во для проверки */
                $checkAmount = ( $totalAmount ) ?: $storeItem["STORE_QUANTITY"];

                if ( $checkAmount < $storeItem["BASKET_QUANTITY"] )
                {
                    $isEnoughAmountOnStore = false;
                    break;
                }
            }
        }

        return $isEnoughAmountOnStore;
    }

    /**
     * метод возвращает трек номер отгрузки
     * @param Sale\Order $order
     * @param int $deliveryId
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getShipmentInfo(Sale\Order $order, $deliveryId = 0)
    {
        $shipmentInfo = [];
        $shipmentCollection = $order->getShipmentCollection();

        /* @var Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if
            (
                $shipment->isSystem()
                ||
                (
                    $deliveryId
                    &&
                    $deliveryId != $shipment->getDeliveryId()
                )
            )
                continue;

            $shipmentInfo = [
                "DELIVERY_ID" => $shipment->getDeliveryId(),
                "TRACKING_NUMBER" => $shipment->getField("TRACKING_NUMBER"),
                "CONFIG" => $shipment->getDelivery()->getConfigValues(),
            ];

            /* множественные отгрузки не планируются */
            break;
        }

        return $shipmentInfo;
    }

    public static function deleteTrackNumber(Sale\Order $order, $deliveryId)
    {
        $shipmentCollection = $order->getShipmentCollection();

        /* @var Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if ( $shipment->isSystem() ) continue;

            /* на всякий случай проверяем id доставки */
            $shipmentDeliveryId = $shipment->getDeliveryId();

            if ( $deliveryId == $shipmentDeliveryId )
            {
                $shipment->setFields([
                    "TRACKING_NUMBER" => ""
                ]);

                break;
            }
        }
    }

    public static function setTrackNumber(Sale\Order $order, $deliveryId, $trackNumber)
    {
        $shipmentCollection = $order->getShipmentCollection();

        /* @var Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if ( $shipment->isSystem() ) continue;

            /* на всякий случай проверяем id доставки */
            $shipmentDeliveryId = $shipment->getDeliveryId();

            if ( $deliveryId == $shipmentDeliveryId )
            {
                $shipment->setFields([
                    "TRACKING_NUMBER" => $trackNumber
                ]);

                break;
            }
        }
    }
}