<?php
Use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . "/template.php");

$boxberryPointsList = Neti\Delivery\Classes\BoxBerry\Api::ListPoints($_SESSION["CITY_CODE"]);
$mapPointInfo = "";
?>
<div id="widget_pickup">
    <div id="tabs" class="col-md-12 text-center">
        <ul class="intec-ui intec-ui-control-tabs intec-ui-scheme-current intec-ui-view-2">
            <li class="intec-ui-part-tab active">
                <a href="#list_view" role="tab" data-toggle="tab" aria-expanded="true">
                    <?=Loc::getMessage("LIST_VIEW")?>
                </a>
            </li>
            <li class="intec-ui-part-tab">
                <a href="#map_view" role="tab" data-toggle="tab" aria-expanded="false">
                    <?=Loc::getMessage("MAP_VIEW")?>
                </a>
            </li>
        </ul>
    </div>
    <div id="widget_views" class="col-md-12 intec-ui intec-ui-control-tabs-content">
        <div id="list_view" class="intec-ui-part-tab active" role="tabpanel">
            <div id="list_wrapper" class="col-md-12">
                <? foreach($boxberryPointsList as $boxberryPoint ): ?>
                    <?
                    $pointInfo = "";
                    $buttonText = "Выбрать";

                    if ( isset($_SESSION["CURRENT_POINT_ID"]) && $boxberryPoint["Code"] == $_SESSION["CURRENT_POINT_ID"] )
                    {
                        $pointInfoResult = \Neti\Delivery\Classes\BoxBerry\Api::PointsDescription($boxberryPoint["Code"]);
                        $pointInfo = \Neti\Delivery\Classes\BoxBerry\Api::getPointInfoHtml($pointInfoResult);
                        $mapPointInfo = $pointInfo;
                        $buttonText = "Пункт выбран";
                    }
                    ?>
                    <div class="col-md-12 point_wrapper" data-point-id="<?=$boxberryPoint['Code']?>">
                        <div class="col-md-9 text-left">
                            <p class="boxBerry_point_title"><?=Loc::getMessage("BOXBERRY_POINT_TITLE")?></p>
                            <p class="cityName"><?=$boxberryPoint["CityName"]?>, <?=$boxberryPoint["AddressReduce"]?></p>
                        </div>
                        <div class="col-md-3 text-right">
                            <div class="choose_point_button intec-ui intec-ui-control-button intec-ui-mod-round-1 intec-ui-scheme-current" data-point-id="<?=$boxberryPoint['Code']?>"><?=$buttonText?></div>
                        </div>
                    </div>
                    <div
                        class="col-md-12 pointInfoWrapper"
                        data-point-id="<?=$boxberryPoint['Code']?>"
                        style="<?=(strlen($pointInfo) == 0) ? 'display: none' : ''?>"
                    ><?=$pointInfo?></div>
                <? endforeach; ?>
            </div>
        </div>
        <div id="map_view" class="intec-ui-part-tab" role="tabpanel">
            <?include_once __DIR__ . "/map.php";?>
            <div id="under_map_point_info" class="col-md-12">
                <?=$mapPointInfo?>
            </div>
        </div>
    </div>
</div>
