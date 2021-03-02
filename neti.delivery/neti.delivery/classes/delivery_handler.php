<?php
namespace Neti\Delivery\Classes;

use \Bitrix\Main\Diag\Debug;
use \Bitrix\Main\Event;
use \Bitrix\Main\EventResult;
use \Bitrix\Main\Loader;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Sale\Delivery;
use \Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use \Neti\Delivery\Classes\BoxBerry\Data as BoxBerry_Data;
use \Neti\Delivery\Classes\Profiles\Base;
use \Bitrix\Sale;
use \Neti\Delivery\Classes\Delivery as Neti_Delivery;

Loc::loadMessages(__FILE__);

/**
 * обработчик сервиса доставки
 * Class DeliveryHandler
 * @package Neti\Delivery\Classes
 */
class DeliveryHandler extends Delivery\Services\Base
{
    /**
     * флаг запрета работы обработчика (для исключения зацикленности)
     * @var bool
     */
    private static $handlerDisallow = false;
    protected static $isCalculatePriceImmediately = true;
    protected static $whetherAdminExtraServicesShow  = false;
    protected static $canHasProfiles = true;
    protected static $cityCode;
    public const AVAILABLE_DELIVERY_SEND_STATUS = "Z"; //код статуса при котором можно отправлять данные в boxberry
    public const PICKUP_TYPE = 1;
    public const COURIER_TYPE = 2;
    public const ADD_ACTION = "add";
    public const UPDATE_ACTION = "update";
    public const DELETE_ACTION = "delete";
    /* @var Sale\Shipment $shipmentOrder */
    private static $shipmentOrder;

    public static function canHasProfiles()
    {
        return self::$canHasProfiles;
    }

    public static function getChildrenClassNames()
    {
        return [
            "1" => '\Neti\Delivery\Classes\Profiles\Pickup_cash', // профиль самовывоза с наложенным платежом
            "2" => '\Neti\Delivery\Classes\Profiles\Pickup', // профиль самовывоза без наложенного платежа
            "3" => '\Neti\Delivery\Classes\Profiles\Courier', // профиль курьерская доставка с наложенным платежом
            "4" => '\Neti\Delivery\Classes\Profiles\Courier_cash', // профиль курьерская доставка без наложенного платежа
        ];
    }

    public function getProfilesList()
    {
        return [
            "1" => Loc::getMessage("PICKUP_CASH_PROFILE_TITLE"),
            "2" => Loc::getMessage("PICKUP_PROFILE_TITLE"),
            "3" => Loc::getMessage("COURIER_CASH_TITLE"),
            "4" => Loc::getMessage("COURIER_TITLE"),
        ];
    }

    public static function getModuleId()
    {
        return "neti.delivery";
    }

    public function __construct(array $initParams)
    {
        parent::__construct($initParams);
    }

    public static function getClassTitle()
    {
        return Loc::getMessage("DELIVERY_TITLE");
    }

    public static function getClassDescription()
    {
        return 'Модифицированная доставка для различных сервисов';
    }

    public function isCalculatePriceImmediately()
    {
        return self::$isCalculatePriceImmediately;
    }

    public static function whetherAdminExtraServicesShow()
    {
        return self::$whetherAdminExtraServicesShow;
    }

    /* метод добавляет службу в систему */
    public static function addDeliveryService()
    {
        return new EventResult(
            EventResult::SUCCESS,
            [
                "Neti\Delivery\Classes\DeliveryHandler" => "/local/modules/neti.delivery/classes/delivery_handler.php",
            ]
        );
    }

    public static function setCityCode ($cityCode)
    {
        self::$cityCode = $cityCode;
        $_SESSION["CITY_CODE"] = $cityCode;
    }

    public static function WidgetInit()
    {
        global $APPLICATION;

        if ( strpos($APPLICATION->GetCurPage(), 'bitrix/admin') === false || !ADMIN_SECTION )
        {
            $APPLICATION->IncludeComponent("neti:delivery.widget", "", [
                "CITY_CODE" => $_SESSION["CITY_CODE"],
            ], false);
        }
    }

    private static function getUserInfo($data, $personTypeId)
    {
        switch ( $personTypeId )
        {
            case "1":
                $userInfo = [
                    "fio" => $data["FIO"],
                    "phone" => $data["PHONE"],
                    "phone2" => "",
                    "email" => $data["EMAIL"],
                    "address" => $data["ADDRESS"],
                ];
                break;

            case "2":
                $userInfo = [
                    "fio" => $data["BOSS_FIO"],
                    "phone" => $data["PHONE"],
                    "phone2" => "",
                    "email" => $data["EMAIL"],
                    "name" => $data["COMPANY"],
                    "address" => $data["COMPANY_ADDRESS"],
                    "inn" => $data["INN"],
                    "kpp" => $data["KPP"],
                    "r_s" => $data["RASCHET"],
                    "bank" => $data["BANK"],
                    "kor_s" => $data["KORRSCHET"],
                    "bik" => $data["BIK"],
                ];
                break;

            default:
                $userInfo = [];
                break;
        }

        return $userInfo;
    }

    private static function checkSplit(\Bitrix\Sale\Order $order)
    {
        $shipmentCollection = $order->getShipmentCollection();
        $deliveryCode = false;

        /* @var \Bitrix\Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if ( $shipment->isSystem() ) continue;
            $deliveryCode = $shipment->getDelivery()->getConfigValues()["ADDITIONAL"]["CODE"];
            break;
        }

        if
        (
            $deliveryCode
            &&
            in_array($deliveryCode, Base::$profileCodes)
            &&
            !$order->isCanceled()
        )
        {
            $shipmentCity = Order::getShipmentCity($order);

            if ( $shipmentCity["SHIPMENT_TIME_DAY"] === false )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * метод возвращает истину если найдены ошибки в других обработчиках
     * @param Event $event
     * @return bool
     */
    private static function getErrorHandlers(Event $event)
    {
        /* проверка на ошибки в других обработчиках */
        foreach( $event->getResults() as $previousResult )
        {
            if( $previousResult->getType() != EventResult::SUCCESS )
                return true;
        }

        return false;
    }

    public static function OnOrderSavedHandler(Event $event)
    {
        /* защита от зацикливания обработчика */
        if ( self::$handlerDisallow )
            return;

        /* выставляем флаг, запрещающий работу обработчика при следующем вызове после сохранения заказа в этом методе */
        self::$handlerDisallow = true;

        if ( !Loader::includeModule("sale") ) return;

        /* проверка на ошибки в других обработчиках */
        if ( self::getErrorHandlers($event) )
            return;

        /* @var \Bitrix\Sale\Order $order */
        $order = $event->getParameter("ENTITY");
        $status = $order->getField("STATUS_ID");

        /* если заказ отменён или уже отгружен - прекращаем работу обработчика */
        if ( $order->isShipped() ) return;

        /* проверка на разделения заказа для складов МСК и СПБ */
        if ( self::checkSplit($order) )
        {
            Order::split($order);
            return;
        }

        /* собираем все нужные значения свойств с заказа */
        $orderProps = Order::getOrderProps($order);
        /**/

        $personTypeId = $order->getPersonTypeId();
        $deliveryId = $order->getField("DELIVERY_ID");
        $actionOrder = ""; //что делать с заказом (добавить, обновить, удалить)
        self::setShipment($order);
        $locationInfo = Base::getLocationInfo($orderProps["LOCATION"]);
        $locationName = $locationInfo["NAME_LANG"];

        /* получаем информацию по отгрузке */
        $shipmentInfo = Shipment::getShipmentInfo($order, $deliveryId);
        /**/

        $deliveryCode = $shipmentInfo["CONFIG"]["ADDITIONAL"]["CODE"];
        $deliveryTrack = $shipmentInfo["TRACKING_NUMBER"];

        /* код выбранного города из базы сервиса BoxBerry */
        $cityServiceCode = Base::getCityCodeFromService($locationName);

        /* информация по пользователю */
        $userInfo = self::getUserInfo($orderProps, $personTypeId);

        if ( is_array($userInfo) && count($userInfo) > 0 )
        {
            if ( $deliveryCode )
            {
                list($pickupCode, $deliveryType, $needPayment, $isModifyDelivery) = Neti_Delivery::getDeliveryTypeInfo(
                    $deliveryCode,
                    $cityServiceCode,
                    $orderProps
                );
            }

            if ( $isModifyDelivery )
            {
                /* если заказ отменён и модифицированная доставка - удаляем заказ из сервиса и очищаем трек номер */
                if ( $order->isCanceled() )
                {
                    $actionOrder = ( strlen($deliveryTrack) > 0 ) ? self::DELETE_ACTION : "";
                }
                else
                {
                    $actionOrder = ( strlen($deliveryTrack) > 0 ) ? self::UPDATE_ACTION : self::ADD_ACTION;

                    if ( strlen($actionOrder) > 0 )
                    {
                        /* получаем товары из корзины заказа и массив весов */
                        list($arBasketItems, $arWeights) = BoxBerry_Data::getBasketItemsForBoxBerry($order);
                        /**/

                        if ( count($arBasketItems) == 0 ) return;

                        /* массив для заказа boxberry */
                        $arDataForService = [
                            "DELIVERY_TRACK" => $deliveryTrack,
                            "NEED_PAYMENT" => $needPayment,
                            "USER_INFO" => $userInfo,
                            "BASKET_ITEMS" => $arBasketItems,
                            "WEIGHTS" => $arWeights,
                            "DELIVERY_TYPE" => $deliveryType,
                            "LOCATION_NAME" => $locationName,
                            "PICKUP_CODE" => $pickupCode,
                        ];

                        /* формируем массив для сервиса */
                        $serviceOrderData = BoxBerry_Data::getServiceOrderData($arDataForService, $order, $orderProps);
                    }
                }
            }

            if ( strlen($actionOrder) > 0 )
            {
                $actionData = [
                    "ACTION" => $actionOrder,
                    "ORDER_STATUS" => $status,
                    "SERVICE_ORDER_DATA" => $serviceOrderData,
                    "DELIVERY_TRACK" => $deliveryTrack,
                    "IS_MODIFY_DELIVERY" => $isModifyDelivery,
                    "DELIVERY_ID" => $deliveryId,
                    "ORDER" => $order
                ];

                list($result, $needToSaveOrder) = BoxBerry_Data::actionOrderHandler($actionData);

                if ( $result["track"] )
                {
                    /* сохраняем идентификатор отслеживания */
                    Shipment::setTrackNumber($order, $deliveryId, $result["track"]);
                }

                /* устанавливаем данные по складу (в базе + коммент отгрузки) */
                self::setStore($order);

                $order->save();
            }
        }
    }

    /**
     * метод устанавливает параметр (объект коллекции) отгрузки класса
     * @param Sale\Order $order
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     */
    private static function setShipment(Sale\Order $order)
    {
        $shipmentCollection = $order->getShipmentCollection();

        /* @var \Bitrix\Sale\Shipment $shipment */
        foreach ( $shipmentCollection as $shipment )
        {
            if ( $shipment->isSystem() ) continue;

            self::$shipmentOrder = $shipment;
            break;
        }
    }

    /**
     * метод устанавливает id склада для отгрузки и добавляет комментарий
     * @param Sale\Order $order
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotSupportedException
     */
    private static function setStore(\Bitrix\Sale\Order $order)
    {
        $shipment = self::$shipmentOrder;

        $shipmentCity = Order::getShipmentCity($order);
        $arStores = Store::getStores();
        $storeCode = array_search($shipmentCity["POINT_CODE"], Shipment::$parcelBoxberryCode);

        Order::setShipmentStore($shipment, $arStores[$storeCode]);
    }
}