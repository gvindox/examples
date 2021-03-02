<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;

Loader::includeModule("neti.delivery");

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$postResult = $request->getPostList()->getValues();

if ( $postResult["action"] && $postResult["pointId"] )
{
    $result = [];
    switch ( $postResult["action"] )
    {
        case "getPointInfo":
            $_SESSION["CURRENT_POINT_ID"] = $postResult["pointId"];
            $result = BoxBerry_Api::PointsDescription($postResult["pointId"]);
            $html = BoxBerry_Api::getPointInfoHtml(array_merge($result, ["mapView" => $postResult["mapView"]]));
            $address = $result["AddressReduce"];
            unset($result);

            $result = [
                "html" => $html,
                "address" => $address,
                "mapView" => $postResult["mapView"],
            ];
            break;

        case "refreshWidget":
            ob_start();
            include __DIR__ . "/widget.php";
            $result["html"] = ob_get_contents();
            ob_end_clean();

            break;
    }

    echo json_encode($result);
}


