<?php
namespace Neti\Delivery\Classes;
use Bitrix\Main\Diag\Debug;
use \Bitrix\Sale;
use Neti\Delivery\Classes\Profiles\Base;

class Order
{

    private static $productInOrderBasket = [];

    /**
     * метод разделяет заказ по складам
     * @param \Bitrix\Sale\Order $order
     */
    public static function split(Sale\Order $order)
    {
        $storeBasketInfo = Store::prepareStoreBasketInfo($order->getBasket());
        $storeKeys = Store::$storeKeys;

        $storeBasketInfo[SAINT_PETERSBURG_ENG]["ITEMS"] = $storeBasketInfo[SAINT_PETERSBURG_ENG]["ITEMS"] + $storeBasketInfo[EXPERT_ENG]["ITEMS"];

        $countItemsMsk = count($storeBasketInfo[$storeKeys[0]]["ITEMS"]);
        $countItemsSpb = count($storeBasketInfo[$storeKeys[1]]["ITEMS"]);

        if (
            $countItemsMsk > 0
            &&
            $countItemsSpb > 0
        )
        {
            foreach ( $storeKeys as $storeKey )
            {
                $arStoreItems = $storeBasketInfo[$storeKey]["ITEMS"];
                $storeId = $storeBasketInfo[$storeKey]["ID"];

                if ( $arStoreItems )
                {
                    /* создаём объект нового заказа */
                    $orderNew = Sale\Order::create($order->getSiteId(), $order->getUserId());

                    /* задаём валюту */
                    $orderNew->setField("CURRENCY", $order->getCurrency());

                    /* задаём тип плательщика */
                    $orderNew->setPersonTypeId($order->getPersonTypeId());

                    /* получаем товары корзины */
                    $newOrderBasket = self::getBasket($order, $arStoreItems);

                    /* устанавливаем корзину */
                    self::setBasket($orderNew, $newOrderBasket);

                    /* устанавливаем свойства */
                    self::setOrderProperty($order, $orderNew);

                    /* устанавливаем оплату */
                    self::setPayment($order, $orderNew);

                    /* устанавливаем отгрузку */
                    self::setShipment($order, $orderNew, $storeBasketInfo[$storeKey]);

                    $orderNew->doFinalAction(true);
                    $orderNew->save();
                }
            }

            /* отменяем старый заказ */
            $order->setField("CANCELED","Y");
            $order->save();
        }
    }

    /**
     * Метод возвращает корзину для нового заказа
     * @param Sale\Order $order - объект заказа
     * @param $arStoreItems - массив товаров сгруппированных по складам
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\NotImplementedException
     */
    private static function getBasket(Sale\Order $order, $arStoreItems)
    {
        $basketItems = [];

        /* @var Sale\BasketItem $basketItem */
        foreach ( $order->getBasket()->getBasketItems() as $basketItem )
        {
            $productId = $basketItem->getProductId();
            $storeItem = $arStoreItems[$productId];

            if ( $storeItem )
            {
                $quantityStoreItem = (float)$storeItem["STORE_QUANTITY"];
                $quantityBasketItem = (float)$storeItem["BASKET_QUANTITY"];
                $quantityAlreadyInAnotherOrder = (float)self::$productInOrderBasket[$productId]["ALREADY_QUANTITY_IN_ANOTHER_ORDER"];

                /* если в другие заказы уже добавлено суммарное количество старой корзины - пропускаем */
                if ( $quantityAlreadyInAnotherOrder == $quantityBasketItem ) continue;

                $quantityItem = $quantityBasketItem;

                /* если количество товара на складе меньше чем в корзине - устанавливаем количество со склада */
                if ( $quantityStoreItem < $quantityBasketItem )
                    $quantityItem = $quantityStoreItem;

                if ( $quantityAlreadyInAnotherOrder > 0 )
                {
                    $quantityItem = abs($quantityBasketItem - $quantityAlreadyInAnotherOrder);

                    if ( $quantityItem > $quantityStoreItem )
                        $quantityItem = $quantityStoreItem;
                }

                $basketItem->quantity = $quantityItem;

                $basketItems[$productId] = $basketItem;

                self::$productInOrderBasket[$productId] = [
                    "PRODUCT_ID" => $productId,
                    "BASKET_QUANTITY" => $quantityBasketItem,
                    "ALREADY_QUANTITY_IN_ANOTHER_ORDER" => $quantityAlreadyInAnotherOrder + $quantityItem,
                ];
            }
        }

        return $basketItems;
    }

    /**
     * Метод устанавливает корзину для заказа
     * @param Sale\Order $order - объект заказа, для которого нужно установить корзину
     * @param $arBasketItems - список товаров корзины для добавления
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\ArgumentTypeException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\NotSupportedException
     * @throws \Bitrix\Main\ObjectNotFoundException
     */
    private static function setBasket(Sale\Order $order, $arBasketItems)
    {
        $newBasket = Sale\Basket::create($order->getSiteId());

        /* @var Sale\BasketItem $basketItem */
        foreach ( $arBasketItems as $basketItem )
        {
            $newBasketItem = $newBasket->createItem($basketItem->getField("MODULE"), $basketItem->getProductId());

            $newBasketItem->setFields([
                "QUANTITY" => $basketItem->quantity,
                "CURRENCY" => $order->getCurrency(),
                "LID" => $order->getSiteId(),
                "PRODUCT_PROVIDER_CLASS" => $basketItem->getField("PRODUCT_PROVIDER_CLASS"),
            ]);
        }

        $order->setBasket($newBasket);
    }

    /**
     * метод устанавливает свойства для нового заказа, копируя из старого
     * @param Sale\Order $oldOrder - объект старого заказа, из которого копируются свойства
     * @param Sale\Order $newOrder - объект нового заказа
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function setOrderProperty(Sale\Order $oldOrder, Sale\Order $newOrder)
    {
        $disableProps = [
            "TYPE_OF_ACTIVITY"
        ];
        $propertyCollection = $oldOrder->getPropertyCollection();
        $propertyCollectionNew = $newOrder->getPropertyCollection();

        /* @var Sale\PropertyValue $property */
        foreach ( $propertyCollection as $property )
        {
            if ( in_array($property->getField("CODE"), $disableProps) ) continue;

            $newPropValue = $propertyCollectionNew->getItemByOrderPropertyId($property->getPropertyId());
            $newPropValue->setValue($property->getValue());
        }
    }

    /**
     * метод устанавливает оплату для нового заказа, копируя из старого
     * @param Sale\Order $oldOrder - объект старого заказа, из которого копируется оплата
     * @param Sale\Order $newOrder - объект нового заказа
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotSupportedException
     */
    private static function setPayment(Sale\Order $oldOrder, Sale\Order &$newOrder)
    {
        $paymentCollection = $oldOrder->getPaymentCollection();
        $paymentCollectionNew = $newOrder->getPaymentCollection();

        /* @var Sale\Payment $payment */
        foreach ( $paymentCollection as $payment )
        {
            $newPayment = $paymentCollectionNew->createItem(
                Sale\PaySystem\Manager::getObjectById($payment->getPaymentSystemId())
            );

            $newPayment->setFields([
                "CURRENCY" => $newOrder->getCurrency(),
                "SUM" => $newOrder->getPrice(),
            ]);
        }
    }

    private static function setShipment(Sale\Order $oldOrder, Sale\Order $newOrder, $arStore)
    {
        $shipmentCollection = $oldOrder->getShipmentCollection();
        $shipmentCollectionNew = $newOrder->getShipmentCollection();
        $basket = $newOrder->getBasket();
        $basketWeight = $basket->getWeight();

        if ( !$basketWeight )
            $basketWeight = Base::DEFAULT_WEIGHT;

        $locationCode = $newOrder->getPropertyCollection()->getDeliveryLocation()->getValue();
        $locationInfo = Base::getLocationInfo($locationCode);

        /* @var Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if ( $shipment->isSystem() ) continue;

            $shipmentNew = $shipmentCollectionNew->createItem(
                Sale\Delivery\Services\Manager::getObjectById($shipment->getDeliveryId())
            );

            /* устанавливаем вес отгрузки */
            $shipmentNew->setWeight($basketWeight);

            $deliveryCode = $shipment->getDelivery()->getConfigValues()["ADDITIONAL"]["CODE"];

            $deliveryData = [
                "CODE" => $deliveryCode,
                "WEIGHT" => $basketWeight,
                "PROFILE_DELIVERY_CODE" => ToLower(str_replace("MODIFY_", "", $deliveryCode)),
                "ZIP" => $newOrder->getPropertyCollection()->getDeliveryLocationZip()->getValue(),
                "PRICE" => $newOrder->getPrice(),
                "LOCATION" => $locationInfo,
                "PARCEL_CODE" => $arStore["BOXBERRY_PARCEL_CODE"],
                "ORDER_BASKET" => $basket
            ];

            $deliveryCalculate = self::calculateDelivery($deliveryData);

            if ( $deliveryCalculate )
            {
                $shipmentNew->setDeliveryService(
                    Sale\Delivery\Services\Manager::getObjectById($shipment->getDeliveryId())
                );

                $shipmentNew->setBasePriceDelivery($deliveryCalculate["PRICE"]);

                self::setShipmentStore($shipmentNew, $arStore);

                self::fillShipment($shipmentNew, $basket, $arStore["ID"]);
            }

            /* в заказах множественных отгрузок не будет */
            break;
        }
    }

    private static function fillShipment(Sale\Shipment $shipment, Sale\Basket $basket, $storeID)
    {
        $shipmentItemCollection = $shipment->getShipmentItemCollection();

        /* @var Sale\BasketItem $basketItem */
        foreach ( $basket as $basketItem )
        {
            $shipmentItem = $shipmentItemCollection->createItem($basketItem);
            $shipmentItem->setQuantity($basketItem->getQuantity());
            $storeItemCollection = $shipmentItem->getShipmentItemStoreCollection();

            /* программно устанавливаем склад отгрузки для товара */
            $newStoreItem = $storeItemCollection->createItem($basketItem);

            $newStoreItem->setFields([
                'QUANTITY' => $basketItem->getQuantity(),
                'STORE_ID' => $storeID
            ]);
        }
    }

    /**
     * метод устанавливает склад для отгрузки заказа
     * @param Sale\Shipment $shipment
     * @param $arStore
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotSupportedException
     */
    public static function setShipmentStore(Sale\Shipment $shipment, $arStore)
    {
        $shipmentComment = $shipment->getField("COMMENTS");

        /* программно устанавливаем склад отгрузки для всей отгрузки */
        $shipment->setStoreId($arStore["ID"]);

        /* добавляем название склада в комментарий отгрузки */
        $storeComment = "Склад: {$arStore["TITLE"]}, адрес: {$arStore["ADDRESS"]}";

        if ( strlen($shipmentComment) > 0 && stripos($shipmentComment, $storeComment) === false )
        {
            $shipmentComment = $storeComment . "\n" . $shipmentComment;
        }
        elseif ( strlen($shipmentComment) == 0 )
        {
            $shipmentComment = $storeComment;
        }
        else
        {
            $shipmentComment = "";
        }

        if ( strlen($shipmentComment) > 0 )
            $shipment->setField("COMMENTS", $shipmentComment);
    }

    private static function calculateDelivery($data)
    {
        $deliveryClass = new Delivery();

        return $deliveryClass->calculateDeliveryByDistrict(
            $data["LOCATION"],
            $data["ORDER_BASKET"],
            [
                "WEIGHT" => $data["WEIGHT"],
                "PROFILE_DELIVERY_CODE" => $data["PROFILE_DELIVERY_CODE"],
                "ZIP" => $data["ZIP"],
                "PRICE" => $data["PRICE"],
            ],
            $data["PARCEL_CODE"]
        );
    }

    public static function getShipmentCity(Sale\Order $order)
    {
        $locationCode = $order->getPropertyCollection()->getDeliveryLocation()->getValue();
        $locationInfo = Base::getLocationInfo($locationCode);
        return Shipment::getShipmentCity($locationInfo, $order->getBasket());
    }

    /**
     * метод возвращает свойства заказа
     * @param Sale\Order $order
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getOrderProps(Sale\Order $order)
    {
        $orderProps = [];
        $propertyCollection = $order->getPropertyCollection();

        foreach ( $propertyCollection as $property )
        {
            $propertyCode = $property->getField("CODE");

            if ( in_array($propertyCode, self::getAvailableOrderProps()) )
            {
                $orderProps[$propertyCode] = $property->getValue();
            }
        }

        return $orderProps;
    }

    /**
     * метод возвращает список символьных кодов свойств, которые можно выбрать из заказа
     * @return string[]
     */
    private static function getAvailableOrderProps()
    {
        return [
            "BOSS_FIO",
            "PHONE",
            "EMAIL",
            "COMPANY",
            "COMPANY_ADDRESS",
            "INN",
            "KPP",
            "RASCHET",
            "BANK",
            "KORRSCHET",
            "BIK",
            "FIO",
            "ZIP",
            "ADDRESS",
            "LOCATION"
        ];
    }
}