<?php
namespace Neti\Delivery\Classes\BoxBerry;
use Bitrix\Main\Diag\Debug;
use Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use Neti\Delivery\Classes\DeliveryHandler;
use Neti\Delivery\Classes\Profiles\Base;
use \Bitrix\Sale;
use Neti\Delivery\Classes\Shipment;

/**
 * класс для работы со структурой данных boxberry
 * Class Data
 * @package Neti\Delivery\Classes\BoxBerry
 */
class Data
{
    /**
     * Метод возвращает список товаров корзины и список весов для BoxBerry
     * @param Sale\Order $order
     * @return array
     */
    public static function getBasketItemsForBoxBerry(Sale\Order $order)
    {
        $arBasketItems = [];
        $arWeights = [];
        $orderBasketItems = $order->getBasket()->getBasketItems();

        /* @var Sale\BasketItem $basketItem */
        foreach ( $orderBasketItems as $key => $basketItem )
        {
            $vat = ( $basketItem->getField('VAT_RATE') == 0 ) ? "10" : $basketItem->getField('VAT_RATE');

            $weight = ( $basketItem->getWeight() == 0 ) ? Base::DEFAULT_WEIGHT : (int)$basketItem->getWeight() * $basketItem->getQuantity();

            $arBasketItems[] = [
                "id" => $basketItem->getProductId(),
                "name" => $basketItem->getField('NAME'),
                "UnitName" => $basketItem->getField('MEASURE_NAME'),
                "nds" => $vat,
                "price" => $basketItem->getPrice(),
                "quantity" => $basketItem->getQuantity(),
            ];

            $keyWeight = ( $key == 0 ) ? "weight" : "weight{$key}";

            $arWeights[$keyWeight] = $weight;
        }

        return [
            $arBasketItems,
            $arWeights
        ];
    }

    /**
     * метод формирует список данных для заказа в boxberry
     * @param $arData
     * @param Sale\Order $order
     * @param $orderProps
     * @return array
     */
    public static function getServiceOrderData($arData, Sale\Order $order, $orderProps)
    {
        $serviceOrderData = [
            "updateByTrack" => ($arData["DELIVERY_TRACK"]) ?: "",
            "order_id" => (string)$order->getId(),
            "price" => $order->getPrice(),
            "payment_sum" => ($arData["NEED_PAYMENT"]) ? $order->getPrice() : 0,
            "delivery_sum" => $order->getDeliveryPrice(),
            "customer" => $arData["USER_INFO"], //информация о покупателе
            "items" => $arData["BASKET_ITEMS"],
            "weights" => $arData["WEIGHTS"],
            "notice" => ($order->getField("USER_DESCRIPTION")) ?: "", //примечание к заказу
            "vid" => $arData["DELIVERY_TYPE"],
        ];

        if ( $arData["DELIVERY_TYPE"] == DeliveryHandler::PICKUP_TYPE )
        {
            $serviceOrderData["shop"] = [
                "name" => ($arData["PICKUP_CODE"]) ?: "",
                "name1" => ($arData["PICKUP_CODE"]) ?: ""
            ];
        }
        elseif ( $arData["DELIVERY_TYPE"] == DeliveryHandler::COURIER_TYPE )
        {
            $serviceOrderData["kurdost"] =
                [
                    "index" => $orderProps["ZIP"],
                    "city" => $arData["LOCATION_NAME"],
                    "addressp" => $arData["USER_INFO"]["address"],
                ];
        }

        return $serviceOrderData;
    }

    /**
     * метод для обработки действий с заказом boxberry
     * @param $arData
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function actionOrderHandler($arData)
    {
        $result = false;
        $needToSave = false;

        switch ($arData["ACTION"])
        {
            case DeliveryHandler::ADD_ACTION:
                /* для добавления заказа в boxberry (если нет трекномера) заказ должен быть согласован с клиентом */
                if ( $arData["ORDER_STATUS"] == DeliveryHandler::AVAILABLE_DELIVERY_SEND_STATUS )
                {
                    $result = BoxBerry_Api::ParselCreate($arData["SERVICE_ORDER_DATA"]);
                    $needToSave = true;
                }
                break;

            case DeliveryHandler::UPDATE_ACTION:
                /* для обновления заказа в boxberry (если есть трекномер) заказ должен быть согласован с клиентом */
                if ( $arData["ORDER_STATUS"] == DeliveryHandler::AVAILABLE_DELIVERY_SEND_STATUS )
                {
                    $serviceOrderData["updateByTrack"] = $arData["DELIVERY_TRACK"];
                    $result = BoxBerry_Api::ParselCreate($arData["SERVICE_ORDER_DATA"]);
                    $needToSave = true;
                }
                break;

            case DeliveryHandler::DELETE_ACTION:
                if ( strlen($arData["DELIVERY_TRACK"]) > 0 && $arData["IS_MODIFY_DELIVERY"] )
                {
                    $result = BoxBerry_Api::ParselDel($arData["DELIVERY_TRACK"]);

                    if ( count($result) > 0 && !$result["error"] )
                    {
                        Shipment::deleteTrackNumber($arData["ORDER"], $arData["DELIVERY_ID"]);
                    }
                }
                $needToSave = true;
                break;
        }

        return [
            $result,
            $needToSave
        ];
    }
}