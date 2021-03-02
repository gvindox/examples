<?
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

use Bitrix\Main\Page\Asset;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION;
CJSCore::Init(["jquery", "popup"]);
?>
<div id="widget_pickup" style="display: none;">

</div>
<script>
    var choosePickupPopup = new BX.PopupWindow(
        "widget_content",
        null,
        {
            content: BX('widget_pickup'),
            closeIcon: {},
            titleBar: {
                content: BX.create("span", {
                    html: '<b><?=Loc::getMessage("WIDGET_TITLE")?></b>',
                    'props': {
                        'className': 'access-title-bar'
                    }
                })
            },
            zIndex: 0,
            offsetLeft: 0,
            offsetTop: 0,
            draggable: false,
            width: 1000,
            overlay: true,
    });

    $(window).load(function ()
    {
        $(document).on("click", "#pickup_widget_link", function ()
        {
            delivery_widget.ajaxRequest({action: "refreshWidget", pointId: "<?=$arParams["CITY_CODE"]?>"});
        });

        showWidgetAfterLoad();
    });

    BX.addCustomEvent('onAjaxSuccess', function()
    {
        showWidgetAfterLoad();
    });

    function showWidgetAfterLoad()
    {
        var widget_button = $(document).find("#pickup_widget_link");

        if ( widget_button )
        {
            if ( widget_button.is(":visible") && widget_button.attr("data-show-on-load") == "Y" )
            {
                widget_button.attr("data-show-on-load", "N");
                delivery_widget.ajaxRequest({action: "refreshWidget", pointId: "<?=$arParams["CITY_CODE"]?>"});
            }
        }
    }
</script>