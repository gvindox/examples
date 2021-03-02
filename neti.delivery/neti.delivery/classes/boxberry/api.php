<?php
namespace Neti\Delivery\Classes\BoxBerry;

use \Bitrix\Main\Config\Option;
use \Bitrix\Main\Diag\Debug;
use \Neti\Delivery\Classes\DeliveryHandler;
use \Bitrix\Main\Data\Cache;
use \Bitrix\Main\Web\HttpClient;

class Api
{
    public static function getToken()
    {
        return Option::get(DeliveryHandler::getModuleId(), "boxberry_api_token");
    }

    /**
     * метод делает запрос к сервису и возрващает ответ
     * @param $method_api - метод сервиса
     * @param array $data - данные для сервиса
     * @param bool $isPost - является ли тип запроса Post
     * @return bool|mixed|string -
     */
    private static function get_request($method_api, $data = array(), $isPost = false)
    {
        $api_url = "https://api.boxberry.ru/json.php";
        $arQueryParams = array_merge($data, [
            "token" => self::getToken(),
            "method" => $method_api,
        ]);

        $resultRequest = false;

        $api_url_with_query = $api_url . "?" . http_build_query($arQueryParams);

        $cache = Cache::createInstance();
        $cacheId = serialize($arQueryParams);
        $cacheTime = 60 * 60 * 24 * 10;

        if ( $isPost === true )
            $cacheTime = 0;

        if ( $cache->initCache($cacheTime, $cacheId) )
        {
            $resultRequest = $cache->getVars();
        }
        elseif ( $cache->startDataCache() )
        {
            $httpClientOptions = [
                "waitResponse" => true,
                "socketTimeout" => 30,
            ];

            $httpClient = new HttpClient($httpClientOptions);

            if ( $isPost === true )
            {
                switch ( $method_api )
                {
                    case "ParselCreate":
                        $arQueryParams = [
                            "token" => self::getToken(),
                            "method" => $method_api,
                            "sdata" => json_encode($data)
                        ];

                        $resultRequest = $httpClient->post($api_url, $arQueryParams, false);
                        break;

                    default:
                        $resultRequest = false;
                        break;
                }
            }
            else
                $resultRequest = $httpClient->get($api_url_with_query);

            $cache->endDataCache($resultRequest);
        }

        return $resultRequest;
    }

    public static function getCityListFull()
    {
        return self::get_request("ListCitiesFull", ["CountryCode" => 643]);
    }

    public static function getListCities()
    {
        return \Bitrix\Main\Web\Json::decode(self::get_request("ListCities"));
    }

    public static function ListPoints($cityCode = false)
    {
        $arParams = [];

        if ( $cityCode && is_numeric($cityCode) )
        {
            $arParams["CityCode"] = $cityCode;
        }

        return \Bitrix\Main\Web\Json::decode(self::get_request("ListPoints", $arParams));
    }

    /* расчёт доставки Склад-Склад и курьерская доставка */
    public static function deliveryCost($data = array())
    {
        $arParams = [
            "weight" => $data["WEIGHT"],
            "target" => $data["TARGET"],
            "ordersum" => $data["ORDER_SUM"],
            "paysum" => $data["PAY_SUM"],
            "targetstart" => $data["TARGET_START"]
        ];

        if ( $data["ZIP"] )
        {
            $arParams["zip"] = $data["ZIP"];
        }

        return \Bitrix\Main\Web\Json::decode(self::get_request("DeliveryCosts", $arParams));
    }

    /* метод возвращает дополнительную информацию по пункту доставки */
    public static function PointsDescription($pointCode)
    {
        if ( !$pointCode || !is_numeric($pointCode) ) return false;

        return \Bitrix\Main\Web\Json::decode(self::get_request("PointsDescription", ["code" => $pointCode, "photo" => "1"]));
    }

    public static function getPointInfoHtml($data)
    {
        $html = "";

        $html .= '<div class="col-md-12 pointInfo">';
        $html .= '</div>';

        $html .= '<div class="pointSchedule col-md-12"><span class="weight_bold">Время работы:</span> '. $data["WorkShedule"] .'</div>';
        $html .= '<div class="pointPayment col-md-12"><span class="weight_bold">Как добраться:</span> '. $data["TripDescription"] .'</div>';

        return $html;
    }


    /**
     * метод добавляет или обновляет заказ в boxberry
     * @param $data
     * @return mixed|null
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function ParselCreate($data)
    {
        return \Bitrix\Main\Web\Json::decode(self::get_request("ParselCreate", $data, true));
    }

    public static function ParselDel($trackNumber)
    {
        return \Bitrix\Main\Web\Json::decode(self::get_request("ParselDel", ["ImId" => $trackNumber]));
    }

    public static function PointsForParcels()
    {
        return \Bitrix\Main\Web\Json::decode(self::get_request("PointsForParcels"));
    }

    public static function ZipCheck($zip)
    {
        return \Bitrix\Main\Web\Json::decode(self::get_request("ZipCheck", ["Zip" => $zip]));
    }
}