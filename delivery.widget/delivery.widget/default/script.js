$(document).ready(function ()
{
    $(document).on("click", "div#widget_pickup .choose_point_button", function ()
    {
        delivery_widget.getPointInfo($(this).attr("data-point-id"), false);
    });
});

delivery_widget = function () {

}

delivery_widget.getPointInfo = function (pointId, mapView)
{
    if ( !mapView ) mapView = false;

    var params = {
        action: "getPointInfo",
        pointId: pointId,
        mapView: mapView
    };

    delivery_widget.ajaxRequest(params);
}

delivery_widget.showWait = function()
{
    BX.Sale.OrderAjaxComponent.startLoader();
}

delivery_widget.closeWait = function()
{
    BX.Sale.OrderAjaxComponent.endLoader();
}

delivery_widget.ajaxRequest = function (params)
{
    delivery_widget.showWait();
    BX.ajax({
        url: "/local/components/neti/delivery.widget/templates/.default/ajax.php",
        data: params,
        method: "POST",
        dataType: 'json',
        timeout: 30,
        cache: false,
        onsuccess: function (result)
        {
            switch ( params["action"] )
            {
                case "getPointInfo":
                    var infoBlock = $('#point_detail_info'); //блок в который будет подставляться детальная информация по точке

                    if ( result.html && infoBlock )
                    {
                        $(".choose_point_button").text("Выбрать");

                        infoBlock.html(result.html).show();
                        choosePickupPopup.close();
                    }

                    if ( result.address )
                    {
                        $('textarea[data-code="ADDRESS"]').val(result.address);
                        $("#current_point_selected").html("<strong>Текущий пункт</strong>: " + result.address);
                    }
                    break;

                case "refreshWidget":
                    $('textarea[data-code="ADDRESS"]').attr("readonly", "readonly");
                    $("#widget_pickup").html(result.html);
                    choosePickupPopup.show();

                    $('div#widget_pickup .pointInfoWrapper').each(function ()
                    {
                        if ( $(this).html().length != 0 )
                        {
                            var pointId = $(this).attr("data-point-id");

                            var address = $('div.point_wrapper[data-point-id="'+ pointId +'"]').find("p.cityName").text();

                            $('textarea[data-code="ADDRESS"]').val(address);
                            return false;
                        }
                    });
                    break;
            }

            delivery_widget.closeWait();
        },
    });
}