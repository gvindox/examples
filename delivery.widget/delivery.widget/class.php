<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

class NetiDeliveryWidget extends CBitrixComponent
{
    public function onPrepareComponentParams($arParams)
    {
        $additionalParams = [
            "ORDER_PROPS" => [
                "ADDRESS" => "ADDRESS",
                "REGULAR_USER_ID" => 1,
                "LEGAL_USER_ID" => 2,
            ],
        ];

        $arParams = array_merge($arParams, $additionalParams);

        return $arParams;
    }

    public function executeComponent()
    {
        if ( \Bitrix\Main\Loader::includeModule("neti.delivery") )
        {
            $this->IncludeComponentTemplate();
        }
    }
}