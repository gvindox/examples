<?php
namespace Neti\Delivery\Classes;

use Bitrix\Main\Diag\Debug;
use Bitrix\Main\SystemException;
use Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use Neti\Delivery\Classes\Profiles\Base;
use Neti\Delivery\Classes\Shipment as Neti_Shipment;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Delivery
{
    private $parcelCode = 0;

    private function setParcelCode($pointCode)
    {
        if (!$this->parcelCode)
        {
            $this->parcelCode = $pointCode;
        }
    }

    /**
     * метод возвращает стоимость и срок доставки от склада учитывая область местоположения
     * @param $locationInfo - данные местоположения
     * @param $orderBasket - объект корзины заказа
     * @param $orderData - данные заказа
     * @param int $parcelCode - код точки отгрузки
     * @return bool|mixed|null
     */
    public function calculateDeliveryByDistrict($locationInfo, $orderBasket, $orderData, $parcelCode = 0)
    {
        $deliveryCostData = $periodDelivery = [];
        $locationName = $locationInfo["NAME_LANG"];

        if ( $locationName )
        {
            if ( !$parcelCode )
            {
                /* расчёт города для отгрузки */
                try
                {
                    $shipmentCityInfo = Neti_Shipment::getShipmentCity($locationInfo, $orderBasket);
                }
                catch (SystemException $e)
                {
                    return false;
                }

                if ( $shipmentCityInfo === false )
                    return false;

                $this->setParcelCode($shipmentCityInfo["POINT_CODE"]);
                $parcelCode = $this->parcelCode;
                /**/
            }

            /* ищем текущее местположение по базе боксберри */
            $boxBerryCityCode = Base::getCityCodeFromService($locationName);
            /**/

            if ( $boxBerryCityCode )
            {
                DeliveryHandler::setCityCode($boxBerryCityCode);
                $listPointsBoxBerry = BoxBerry_Api::ListPoints($boxBerryCityCode);

                $paramsForCalculate = [
                    "WEIGHT" => $orderData["WEIGHT"],
                    "TARGET" => $listPointsBoxBerry[0]["Code"],
                    "TARGET_START" => $parcelCode
                ];

                $profileDeliveryType = $orderData["PROFILE_DELIVERY_CODE"];
                $additionalParamsForCalculate = $this->getAdditionalForCalculateParams($profileDeliveryType, $orderData);

                if ($additionalParamsForCalculate["ERROR"])
                    return $additionalParamsForCalculate;

                $paramsForCalculate = array_merge($paramsForCalculate, $additionalParamsForCalculate);

                $deliveryCostData = BoxBerry_Api::deliveryCost($paramsForCalculate);
                $periodDelivery = $this->getDeliveryPeriod(
                    $deliveryCostData,
                    $shipmentCityInfo["SHIPMENT_TIME_DAY"],
                    $additionalParamsForCalculate["SET_POINT_LINK"]
                );
            }
        }

        return [
            "PRICE" => $deliveryCostData["price"],
            "PERIOD" => $periodDelivery["PERIOD"],
            "PERIOD_DESCRIPTION" => $periodDelivery["PERIOD_DESCRIPTION"],
        ];
    }

    private function getAdditionalForCalculateParams($profileDeliveryType, $orderData)
    {
        $orderPrice = $orderData["PRICE"];
        $locationZip = $orderData["ZIP"];

        /* сумма к оплате с получателя для доставки с наложенным платежом */
        $paySumNoCashProfile = 0;

        switch ($profileDeliveryType) {
            case "pickup":
                $additionalParamsForCalculate = [
                    "ORDER_SUM" => $orderPrice,
                    "PAY_SUM" => $paySumNoCashProfile,
                    "SET_POINT_LINK" => true,
                ];
                break;

            case "pickup_cash":
                $additionalParamsForCalculate = [
                    "ORDER_SUM" => $orderPrice,
                    "PAY_SUM" => $orderPrice,
                    "SET_POINT_LINK" => true,
                ];
                break;

            case "courier":
                $additionalParamsForCalculate = [
                    "ORDER_SUM" => $orderPrice,
                    "PAY_SUM" => $paySumNoCashProfile,
                    "ZIP" => $locationZip
                ];
                break;

            case "courier_cash":
                $additionalParamsForCalculate = [
                    "ORDER_SUM" => $orderPrice,
                    "PAY_SUM" => $orderPrice,
                    "ZIP" => $locationZip
                ];
                break;

            default:
                $additionalParamsForCalculate["ERROR"] = Loc::getMessage("ERROR_PROFILE_CALCULATE");
                break;
        }

        return $additionalParamsForCalculate;
    }

    /**
     * метод возвращает срок доставки с описанием
     * @param $resultDelivery - результат расчёта доставки с сервиса
     * @param $shipmentPeriod - период отгрузки
     * @param bool $setPointLink - флаг установки ссылки для выбора пункта самовывоза
     * @return array
     */
    private function getDeliveryPeriod($resultDelivery, $shipmentPeriod, $setPointLink = false)
    {
        if ( is_numeric($shipmentPeriod) )
        {
            $deliveryPeriod = $resultDelivery["delivery_period"] + $shipmentPeriod;

            $periodDescription = self::plural_form($deliveryPeriod, array(Loc::getMessage("DAY"), Loc::getMessage("DAYS"), Loc::getMessage("DAYSS")));
        }
        else
        {
            $deliveryPeriod = $shipmentPeriod;
        }

        if ($setPointLink) {
            $pointSelectedHtml = "";

            if ($_SESSION["CURRENT_POINT_ID"]) {
                $pointInfo = BoxBerry_Api::PointsDescription($_SESSION["CURRENT_POINT_ID"]);
                $pointInfoHtml = BoxBerry_Api::getPointInfoHtml($pointInfo);

                if ($_SESSION["CITY_CODE"] == $pointInfo["CityCode"]) {
                    $pointSelectedHtml = "Текущий пункт: {$pointInfo["AddressReduce"]}";
                }
            }

            $pointSelectedHtml = '<p id="current_point_selected">' . $pointSelectedHtml . '</p><div id="point_detail_info" class="row">' . $pointInfoHtml . '</div>';

            $periodDescription .= '<br>' . $pointSelectedHtml . '<a data-show-on-load="Y" id="pickup_widget_link" href="javascript:void(0)">Выбрать пункт выдачи</a>';
        }

        return
            [
                "PERIOD" => $deliveryPeriod,
                "PERIOD_DESCRIPTION" => $periodDescription,
            ];
    }

    private static function plural_form($number, $after)
    {
        $cases = array(2, 0, 1, 1, 1, 2);
        return $number . ' ' . $after[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]] . ' ';
    }

    /**
     * метод возвращает код пункта выдачи, флаг наложенного платежа, флаг, указывающий используется ли модифицированная доставка, тип доставки
     * @param $deliveryCode
     * @param $cityServiceCode
     * @param $orderProps
     * @return array
     */
    public static function getDeliveryTypeInfo($deliveryCode, $cityServiceCode, $orderProps)
    {
        $pickupCode = false; //код пункта выдачи посылки (ПВЗ)
        $deliveryType = DeliveryHandler::PICKUP_TYPE; //тип доставки, 1 - самовывоз (до ПВЗ), 2 - курьерская доставка
        $needPayment = false; //флаг наложенного платежа
        $isModifyDelivery = false; //флаг, указывающий используется ли модифицированная доставка

        switch ($deliveryCode)
        {
            case "MODIFY_PICKUP_CASH":
            case "MODIFY_PICKUP":
                $listPoints = BoxBerry_Api::ListPoints($cityServiceCode);

                foreach ( $listPoints as $listPoint )
                {
                    if ( $listPoint["AddressReduce"] == $orderProps["ADDRESS"] )
                    {
                        $pickupCode = $listPoint["Code"];
                        break;
                    }
                }

                $isModifyDelivery = true;
                break;

            case "MODIFY_COURIER_CASH":
            case "MODIFY_COURIER":
                $deliveryType = DeliveryHandler::COURIER_TYPE;
                $isModifyDelivery = true;
                break;
        }

        switch ($deliveryCode)
        {
            case "MODIFY_COURIER_CASH":
            case "MODIFY_PICKUP_CASH":
                $needPayment = true;
                break;
        }

        return [
            $pickupCode,
            $deliveryType,
            $needPayment,
            $isModifyDelivery
        ];
    }
}