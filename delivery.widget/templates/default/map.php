<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
use Bitrix\Main\Config\Option;

$api_yandex_map_key = "yandex_map_api_key";

if ( !Option::get("fileman", "yandex_map_api_key") )
{
    Option::set("fileman", "yandex_map_api_key", $api_yandex_map_key);
}

$arResult["POSITION"]["PLACEMARKS"] = [];
$gpsExplodeFirstPoint = explode(",", $boxberryPointsList[0]["GPS"]);

$arResult['POSITION']['yandex_lat'] = $gpsExplodeFirstPoint[0];
$arResult['POSITION']['yandex_lon'] = $gpsExplodeFirstPoint[1];
$arResult['POSITION']['yandex_scale'] = "13";

foreach ( $boxberryPointsList as $point )
{
    $gpsExplode = explode(",", $point["GPS"]);
    $colorPoint = "#c86b6b";

    $arResult["POSITION"]["PLACEMARKS"][] = [
        "LAT" => $gpsExplode[0],
        "LON" => $gpsExplode[1],
        "CODE" => $point["Code"],
        "COLOR" => $colorPoint,
    ];
}
?>
<div id="widget_map" class="col-md-12" style="min-height: 500px"></div>
<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU&amp;apikey=<?=$api_yandex_map_key?>" type="text/javascript"></script>
<script>
    ymaps.ready(init);

    function init()
    {
        var myMap = new ymaps.Map(
            "widget_map",
            {
                center: [<?=$arResult['POSITION']['yandex_lat']?>, <?=$arResult['POSITION']['yandex_lon']?>],
                zoom: 13
            },
        );

        <? foreach( $arResult["POSITION"]["PLACEMARKS"] as $placemark ): ?>
        myMap.geoObjects
            .add(new ymaps.Placemark(
                [<?=$placemark["LAT"]?>, <?=$placemark["LON"]?>],
                {
                    codePoint: '<?=$placemark["CODE"]?>'
                },
                {
                    preset: 'islands#icon',
                    iconColor: '<?=$placemark['COLOR']?>',
                }
                )
            );
        <? endforeach; ?>

        myMap.geoObjects.events.add("click", function (e)
        {
            var target = e.get('target');
            delivery_widget.getPointInfo(target.properties.get('codePoint'), true);
        });
    }
</script>
