<?php

namespace Neti\Delivery\Classes\Profiles;

use \Bitrix\Main\Diag\Debug;
use Bitrix\Main\SystemException;
use \Bitrix\Sale\Location\LocationTable;
use \Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use \Bitrix\Main\Data\Cache;
use \Bitrix\Main\Localization\Loc;
use \Neti\Delivery\Classes\DeliveryHandler;
use \Neti\Delivery\Classes\Store;
use \Bitrix\Sale\Delivery\Services;
use \Bitrix\Sale\Shipment;
use \Bitrix\Sale\Delivery;
use \Bitrix\Main\Error;
use \Neti\Delivery\Classes\Shipment as Neti_Shipment;

Loc::loadMessages(__FILE__);

/**
 * Class Base
 * Базовый абстрактный класс профилей
 * @package Neti\Delivery\Classes\Profiles
 */
abstract class Base extends \Bitrix\Sale\Delivery\Services\Base
{
    public static $profileCodes = [
        "MODIFY_COURIER",
        "MODIFY_COURIER_CASH",
        "MODIFY_PICKUP",
        "MODIFY_PICKUP_CASH"
    ];
    protected static $isProfile = true;
    protected static $parent = null;
    private $parcelCode = 0;
    /**
     * вес по умолчанию
     */
    const DEFAULT_WEIGHT = 1000;
    /**
     * ставка НДС по умолчанию
     */
    const DEFAULT_VAT = 10;

    public function __construct(array $initParams)
    {
        parent::__construct($initParams);
        $this->parent = Services\Manager::getObjectById($this->parentId);
    }

    public function getParentService()
    {
        return $this->parent;
    }

    public function isCalculatePriceImmediately()
    {
        return $this->getParentService()->isCalculatePriceImmediately();
    }

    public static function isProfile()
    {
        return self::$isProfile;
    }

    public static function getCityCodeFromService($locationName)
    {
        if (!$locationName) return false;

        $boxBerryList = BoxBerry_Api::getListCities();
        $boxBerryCityCode = 0;

        foreach ( $boxBerryList as $arCity )
        {
            if ( ToLower($arCity["Name"]) == ToLower($locationName) )
            {
                $boxBerryCityCode = $arCity["Code"];
                break;
            }
        }

        return $boxBerryCityCode;
    }

    public static function getLocationInfo($locationCode)
    {
        $locationInfo = [];
        $cache = Cache::createInstance();
        $cacheId = $locationCode . "-info";
        $cacheTime = 60 * 60 * 24 * 10;

        if ($cache->initCache($cacheTime, $cacheId))
        {
            $locationInfo = $cache->getVars();
        }
        elseif ($cache->startDataCache())
        {
            /* информация родителя местоположения второго уровня (Округ) */
            $locationDB = LocationTable::getList(array(
                'filter' => array(
                    '=CODE' => $locationCode,
                    '=PARENTS.NAME.LANGUAGE_ID' => LANGUAGE_ID,
                    '=PARENTS.TYPE.NAME.LANGUAGE_ID' => LANGUAGE_ID,
                    '=PARENTS.DEPTH_LEVEL' => '2',
                    '=NAME.LANGUAGE_ID' => LANGUAGE_ID
                ),
                'select' => array(
                    'I_ID' => 'PARENTS.ID',
                    'I_NAME' => 'PARENTS.NAME.NAME',
                    'I_DEPTH_LEVEL' => 'PARENTS.DEPTH_LEVEL',
                    'ID',
                    'NAME_LANG' => 'NAME.NAME'
                ),
                'order' => array(
                    'PARENTS.DEPTH_LEVEL' => 'asc'
                )
            ))->fetch();

            $locationInfo = [
                "ID" => $locationDB["ID"],
                "NAME_LANG" => $locationDB["NAME_LANG"],
                "DISTRICT" => [
                    "I_DEPTH_LEVEL" => $locationDB["I_DEPTH_LEVEL"],
                    "I_NAME" => $locationDB["I_NAME"],
                    "I_ID" => $locationDB["I_ID"],
                ],
            ];

            $cache->endDataCache($locationInfo);
        }

        return $locationInfo;
    }

    protected function calculateConcrete(Shipment $shipment = null)
    {
        try
        {
            if (!$_REQUEST["via_ajax"] || $_REQUEST["via_ajax"] != "Y")
            {
                unset($_SESSION["CURRENT_POINT_ID"]);
            }

            /* объект расчёта */
            $objCalculate = new Delivery\CalculationResult();/* результат расчёта доставки */
            $resultCalculate = false;

            /* вес отгрузки */
            $weight = $shipment->getWeight();
            $priceDelivery = false;

            /* если вес у товаров не указан, указываем вес по умолчанию */
            if ($weight == 0)
            {
                $weight = self::DEFAULT_WEIGHT;
            }

            /* объект заказа */
            $order = $shipment->getCollection()->getOrder();

            /* стоимость заказа без учёта стоимости доставки */
            $orderPrice = floatval($order->getPrice() - $order->getDeliveryPrice());

            /* корзина заказа */
            $orderBasket = $order->getBasket();
            $props = $order->getPropertyCollection();

            /* код выбранной локации */
            $locationCode = $props->getDeliveryLocation()->getValue();

            /* индекс выбранной локации */
            $locationZip = $props->getDeliveryLocationZip()->getValue();

            /* проверяем по индексу возможность курьерской доставки */
            self::checkZipForKD($locationZip);

            $orderData = [
                "WEIGHT" => $weight,
                "PRICE" => $orderPrice,
                "ZIP" => $locationZip,
                "PROFILE_DELIVERY_CODE" => static::getProfileType()
            ];

            if ($locationCode)
            {
                /* получаем информацию по выбранному пользователем местоположении */
                $locationInfo = self::getLocationInfo($locationCode);
                /* расчитываем стоимость доставки */
                $deliveryClass = new \Neti\Delivery\Classes\Delivery();
                $resultCalculate = $deliveryClass->calculateDeliveryByDistrict($locationInfo, $orderBasket, $orderData);
            }

            if ($resultCalculate["ERROR"])
            {
                $objCalculate->addError(new Error($resultCalculate["ERROR"]));
            }

            if ($resultCalculate === false)
            {
                $objCalculate->addError(new Error(Loc::getMessage("CALCULATE_ERROR")));
            }

            if ($resultCalculate["PRICE"])
            {
                $priceDelivery = $resultCalculate["PRICE"];
            }

            if ($priceDelivery && is_numeric($priceDelivery))
            {
                $periodDescription = $resultCalculate["PERIOD_DESCRIPTION"];

                if ($resultCalculate["PERIOD"] == 0)
                {
                    $priceDelivery = 0;
                    $periodDescription = Loc::getMessage(
                            "MANUAL_CALCULATE"
                        ) . "<br>" . $resultCalculate["PERIOD_DESCRIPTION"];

                    $objCalculate->addError(new Error(Loc::getMessage("ERROR_PROFILE_CALCULATE")));
                }

                $objCalculate->setDeliveryPrice(
                    roundEx(
                        $priceDelivery,
                        SALE_VALUE_PRECISION
                    )
                );

                $objCalculate->setPeriodDescription($periodDescription);
            }
            else
            {
                $objCalculate->addError(new Error(Loc::getMessage("ERROR_PROFILE_CALCULATE")));
            }
        }
        catch (SystemException $e)
        {
            $objCalculate->addError(new Error($e->getMessage()));
        }

        return $objCalculate;
    }

    /**
     * Метод проверяет возможность КД по индексу местоположения
     * @param $zip
     * @throws SystemException
     */
    private function checkZipForKD($zip)
    {
        $profileCode = static::getProfileDefaultCode();

        switch ($profileCode)
        {
            case self::$profileCodes[0]:
            case self::$profileCodes[1]:
                $check = BoxBerry_Api::ZipCheck($zip);

                if ( strlen($check["err"]) > 0 && $check["err"] )
                {
                    throw new SystemException(Loc::getMessage("CALCULATE_ERROR"));
                }
                break;
        }
    }

//    public function isCompatible(\Bitrix\Sale\Shipment $shipment)
//    {
//        $calcResult = self::calculateConcrete($shipment);
//        return $calcResult->isSuccess();
//    }

    /**
     * метод позволяет вытягивать параметры для расчёта из дочерних классов
     * @return mixed
     */
    protected static abstract function getProfileType();

    /**
     * метод вытягивает из профиля символьный код по умолчанию
     * @return mixed
     */
    protected static abstract function getProfileDefaultCode();

    protected function getConfigStructure()
    {
        return array(
            "ADDITIONAL" => array(
                'TITLE' => 'Дополнительные настройки',
                'DESCRIPTION' => 'Дополнительные настройки',
                'ITEMS' => array(
                    'CODE' => array(
                        "TYPE" => 'STRING',
                        "NAME" => 'Символьный код службы доставки',
                        "DEFAULT" => static::getProfileDefaultCode(),
                        "READONLY" => true
                    ),
                )
            )
        );
    }
}