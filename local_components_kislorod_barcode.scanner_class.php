<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
use \Bitrix\Highloadblock as HL;
use \Bitrix\Main\SystemException;
use Bitrix\Main\Application;

class Barcode_Scanner extends CBitrixComponent
{
    private $HL_USER_CLASS;
    private $HL_BARCODE_CLASS;
    private $HL_COLOR_CLASS;
    private $HL_OFFER_CATALOG = "20";
    private $tag_cache = "tag_cache_barcode_scan";

    private function getHL()
    {
        if ( !CModule::IncludeModule('highloadblock') )
            throw new SystemException("Не найден HL модуль");

        $cache_instance = \Bitrix\Main\Data\Cache::createInstance();

        if ( $cache_instance->initCache(7200, serialize(["cache_key"]), "/") )
        {
            $hl_list = $cache_instance->getVars();
        }
        else
        {
            if( $cache_instance->StartDataCache() )
            {
                $hl_list = $this->searchHL();
                $cache_instance->endDataCache($hl_list);
            }
        }

        if ( count($hl_list) == 0 )
            throw new SystemException("Не найдены HL блоки");

        $barcode_hl_id = $hl_list[ToLower("Barcodes")];
        $user_hl_id = $hl_list[ToLower("BarcodeUserHistory")];
        $color_hl_id = $hl_list[ToLower("AsproNextColorReference")];

        $this->makeClassHL($user_hl_id, "USER");
        $this->makeClassHL($barcode_hl_id, "BARCODE");
        $this->makeClassHL($color_hl_id, "COLOR");
    }

    /*поиск HighLoad блоков по коду*/
    private function searchHL()
    {
        $result = [];
        $barcode_code = "Barcodes";
        $user_history_code = "BarcodeUserHistory";
        $color_code = "AsproNextColorReference";

        $db_hl_blocks = HL\HighloadBlockTable::getList([
            "filter" => ["NAME" => [$barcode_code, $user_history_code, $color_code]],
            "select" => ["ID", "NAME"]
        ]);

        while ( $hl_block = $db_hl_blocks->Fetch() )
        {
            $result[ToLower($hl_block["NAME"])] = $hl_block["ID"];
        }

        return $result;
    }

    /*очищаем кеш истории*/
    private function clearTagCache()
    {
        $GLOBALS['CACHE_MANAGER']->ClearByTag($this->tag_cache);
    }

    /*записываем сущности HL для дальнейшей работы*/
    private function makeClassHL($hbId, $type = false)
    {
        if ( $type )
        {
            $hlblock = HL\HighloadBlockTable::getById($hbId)->fetch();
            $entity = HL\HighloadBlockTable::compileEntity($hlblock); //генерация класса

            switch ( $type )
            {
                case "USER":
                    $this->HL_USER_CLASS = $entity->getDataClass();
                    break;

                case "BARCODE":
                    $this->HL_BARCODE_CLASS = $entity->getDataClass();
                    break;

                case "COLOR":
                    $this->HL_COLOR_CLASS = $entity->getDataClass();
                    break;
            }
        }
        else
            throw new SystemException("Пустой тип HL");
    }

    /*собираем данные по пользователю из HL блока*/
    private function getUserHL($userId = false)
    {
        global $USER;
        $result = false;

        if ( !$userId )
            $userId = $USER->GetID();

        if ( !$userId )
            throw new SystemException("ID пользователя не найден");

        $user_hb_class = $this->HL_USER_CLASS;
        $hb_data = $user_hb_class::getList([
            "select"    => ["ID", "UF_USER_ID", "UF_USER_HISTORY"],
            "filter"    => ["UF_USER_ID" => $userId],
            "limit"     => "1"
        ])->Fetch();

        if ( $hb_data["UF_USER_ID"] )
            $result = ["ID" => $hb_data["ID"], "USER_ID" => $userId, "USER_HISTORY" => unserialize($hb_data["UF_USER_HISTORY"])];

        if ( !is_array($result["USER_HISTORY"]) ) $result["USER_HISTORY"] = [];

        return $result;
    }

    private function searchBarcodes($code)
    {
        $result = [];
        $barcode_hb_class = $this->HL_BARCODE_CLASS;
        $hb_data = $barcode_hb_class::getList([
            "select"    => ["UF_ARTICLE", "UF_XMLIDTP", "UF_BARCODE"],
            "filter"    => ["UF_BARCODE" => $code],
        ]);

        if ( $hb_data->getSelectedRowsCount() > 0 )
        {
            while ( $barcode = $hb_data->Fetch() )
            {
                $result[] = $barcode;
            }
        }
        else
            throw new SystemException("Штрихкод {$code} не найден");

        return $result;
    }

    /*получаем цвет предмета*/
    private function getColor($arColor)
    {
        $color_hb_class = $this->HL_COLOR_CLASS;
        $ar_color_xml_id = array_column($arColor, "COLOR_XML_ID");
        $hb_data = $color_hb_class::getList([
            "select"    => ["UF_NAME", "UF_XML_ID"],
            "filter"    => ["UF_XML_ID" => $ar_color_xml_id],
        ]);

        if (  $hb_data->getSelectedRowsCount() > 0 )
        {
            $hb_color = [];

            while ( $color = $hb_data->Fetch() )
            {
                $hb_color[] = $color;
            }

            foreach ( $arColor as &$color )
            {
                foreach ( $hb_color as $hb_color_item )
                {
                    if ( $color["COLOR_XML_ID"] == $hb_color_item["UF_XML_ID"] )
                    {
                        $color["COLOR_NAME"] = $hb_color_item["UF_NAME"];
                        break;
                    }
                }
            }
            unset($color);

            return $arColor;
        }

        return false;
    }

    /*ищем предметы в инфоблоке*/
    private function searchItems($arSearch)
    {
        if ( !$arSearch )
            throw new SystemException("Нечего искать");

        $arFilter = [
            "IBLOCK_ID" => $this->HL_OFFER_CATALOG,
            "ACTIVE" => "Y"
        ];

        foreach ( $arSearch as $search )
        {
            if ( $search["UF_ARTICLE"] )
                $arFilter["PROPERTY_CML2_ARTICLE_VALUE"][] = $search["UF_ARTICLE"];

            if ( $search["UF_XMLIDTP"] )
                $arFilter["XML_ID"][] = $search["UF_XMLIDTP"];
        }

        $arSelect = ["ID", "NAME", "XML_ID", "PROPERTY_CML2_ARTICLE", "DETAIL_PAGE_URL", "PROPERTY_COLOR_REF", "PROPERTY_SIZE", "PREVIEW_PICTURE", "DETAIL_PICTURE", "CATALOG_GROUP_1"];

        if ( CModule::IncludeModule("iblock") )
        {
            $dbItem = CIBlockElement::GetList(false, $arFilter, false, ["nTopCount" => count($arSearch)], $arSelect);

            $arResult = [];
            $colorSearch = [];

            while ( $arItem = $dbItem->GetNext() )
            {
                foreach ( $arSearch as $search )
                {
                    if
                    (
                        $arItem["XML_ID"] == $search["UF_XMLIDTP"]
                        ||
                        ( $arItem["PROPERTY_CML2_ARTICLE_VALUE"] == $search["UF_ARTICLE"] && $arItem["PROPERTY_CML2_ARTICLE_VALUE"] )
                    )
                        $arItem["BARCODE"] = $search["UF_BARCODE"];
                }

                $colorSearch[] = ["ITEM_ID" => $arItem["ID"], "COLOR_XML_ID" => $arItem["PROPERTY_COLOR_REF_VALUE"]];
                $arItem["PRICE"] = number_format($arItem["CATALOG_PRICE_1"], 0, "", " ");

                $arResult[] = $arItem;
            }

            $arColor = $this->getColor($colorSearch);

            foreach ( $arResult as &$arItem )
            {
                foreach ( $arColor as $color )
                {
                    if ( $color["COLOR_XML_ID"] == $arItem["PROPERTY_COLOR_REF_VALUE"] )
                    {
                        $arItem["PROPERTY_COLOR_REF_NAME"] = $color["COLOR_NAME"];
                        break;
                    }
                }

                $picture_id = ( $arItem["PREVIEW_PICTURE"] ) ? $arItem["PREVIEW_PICTURE"] : $arItem["DETAIL_PICTURE"];

                if ( $picture_id )
                {
                    $arResize = CFile::ResizeImageGet($picture_id, ["width" => "160", "height" => "160"], BX_RESIZE_IMAGE_PROPORTIONAL);

                    $arItem["PICTURE"] = $arResize["src"];
                }
            }
            unset($arItem);

            return $arResult;
        }

        return false;
    }

    /*записываем историю пользователя*/
    private function writeToHistory($code)
    {
        if ( !$code )
            throw new SystemException("Нельзя добавить пустой код");

        /*сначала получаем историю пользователя*/
        $userData = $this->getUserHL();
        $hb_user_class = $this->HL_USER_CLASS;

        if ( $userData["USER_ID"] )
        {
            /*добавляем код в историю*/
            if ( !in_array($code, $userData["USER_HISTORY"]) )
            {
                array_push($userData["USER_HISTORY"], $code);

                $arFields = array (
                    'UF_USER_HISTORY' => serialize($userData["USER_HISTORY"]),
                );

                $result = $hb_user_class::update($userData["ID"], $arFields);

                if( $result->isSuccess() )
                {
                    return ["success" => "Штрихкод добавлен в историю"];
                }
                else
                {
                    throw new SystemException($result->getErrorMessages());
                }
            }
            else
                throw new SystemException("Штрихкод уже отсканирован");
        }
        else
        {
            /*добавляем нового пользователя*/
            global $USER;
            $arFields = array (
                'UF_USER_ID' => $USER->GetID(),
                'UF_USER_HISTORY' => serialize([$code]),
            );

            $result = $hb_user_class::add($arFields);

            if( $result->isSuccess() )
            {
                return ["success" => "Штрихкод добавлен в историю"];
            }
            else
            {
                throw new SystemException($result->getErrorMessages());
            }
        }
    }

    /*обрабатываем ajax запрос*/
    public function ajaxRun($type, $data)
    {
        try
        {
            $this->getHL();
            $result = [];

            switch ($type)
            {
                case "searchBarcode":
                    $barcode = $this->searchBarcodes($data)[0];

                    if ( $barcode["UF_ARTICLE"] || $barcode["UF_XMLIDTP"] )
                    {
                        /*поиск ведем сразу по двум поляем, если существуют*/
                        $this->arResult["ITEMS"] = $this->searchItems([$barcode]);

                        if ( $this->arResult["ITEMS"] )
                        {
                            /*если нашли предложение, надо добавить в историю пользователя*/
                            $result = $this->writeToHistory($data);

                            if ( $result["success"] )
                                $this->clearTagCache();
                        }
                    }
                    break;

                case "delete":
                    $result = $this->deleteItemsFromHistory($data);
                    break;

                default:
                    $result = ["error" => "empty type"];
                    break;
            }

            if ( !$result )
                $result = ["error" => "По штрихкоду {$data} ничего не найдено"];

            return $result;
        }
        catch(Exception $error)
        {
            return ["error" => $error->getMessage()];
        }
    }

    /*удаляем штрихкоды из истории*/
    private function deleteItemsFromHistory($arBarcodes)
    {
        $userData = $this->getUserHL();

        if ( is_array($arBarcodes) )
        {
            foreach ( $userData["USER_HISTORY"] as $key => $value )
            {
                if ( in_array($value, $arBarcodes) )
                    unset($userData["USER_HISTORY"][$key]);
            }

            $newHistory = serialize($userData["USER_HISTORY"]);
        }
        else
            $newHistory = "";

        return $this->updateHistory($userData["ID"], $newHistory);
    }

    private function updateHistory($historyID, $history)
    {
        $hb_user_class = $this->HL_USER_CLASS;
        $result = $hb_user_class::update($historyID, ["UF_USER_HISTORY" => $history]);

        if( $result->isSuccess() )
        {
            $this->clearTagCache();
            return ["success" => "История обновлена"];
        }
        else
        {
            throw new SystemException($result->getErrorMessages());
        }
    }

    public function executeComponent()
    {
        global $USER;
        $request = Application::getInstance()->getContext()->getRequest();
        $this->tag_cache .= "-" . $USER->GetID();

        if ( $request->getPost("code") && $request->getPost("is_ajax") === "Y" && $request->getPost("type") )
        {
            try
            {
                global $APPLICATION;
                $APPLICATION->RestartBuffer();
                $result = $this->ajaxRun($request->getPost("type"), $request->getPost("code"));

                if ( $result["success"] )
                {
                    ob_start();
                    $this->includeComponentTemplate();
                    $ob = ob_get_contents();
                    //удаляем лишние переносы и перекодируем в UTF-8 для json формат
                    $result["html"] = mb_convert_encoding(preg_replace( "/\r|\n/", "", $ob ), 'UTF-8', 'UTF-8');
                    ob_end_clean();
                    echo json_encode($result);
                }
                else
                    throw new SystemException($result["error"]);
            }
            catch (Exception $error)
            {
                echo json_encode(["error" => $error->getMessage()]);
            }
        }
        else
        {
            try
            {
                if ( !$USER->IsAuthorized() )
                {
                    global $APPLICATION;
                    $APPLICATION->IncludeComponent(
                        "aspro:auth.next",
                        "main",
                        array(
                            "SEF_MODE" => "Y",
                            "SEF_FOLDER" => "/auth/",
                            "SEF_URL_TEMPLATES" => array(
                                "auth" => "",
                                "registration" => "registration/",
                                "forgot" => "forgot-password/",
                                "change" => "change-password/",
                                "confirm" => "confirm-password/",
                                "confirm_registration" => "confirm-registration/",
                            ),
                            "PERSONAL" => "/personal/",
                        ),
                        false
                    );
                }
                else
                {
                    $this->getHL();

                    $cacheTime = "3600000";
                    $cacheId = serialize(["USER_ID" => $USER->GetID(), "PAGE" => "help-scan"]);
                    $cacheDir = "/barcode/scan";

                    if ( $this->StartResultCache($cacheTime, $cacheId, $cacheDir) )
                    {
                        if (defined('BX_COMP_MANAGED_CACHE') && is_object($GLOBALS['CACHE_MANAGER']))
                        {
                            $GLOBALS['CACHE_MANAGER']->StartTagCache($cacheDir);
                            $GLOBALS['CACHE_MANAGER']->RegisterTag($this->tag_cache);
                            $GLOBALS['CACHE_MANAGER']->EndTagCache();
                        }

                        $userData = $this->getUserHL();

                        if ( count($userData["USER_HISTORY"]) > 0 )
                        {
                            $filter_by_barcodes = $this->searchBarcodes($userData["USER_HISTORY"]);

                            if ( count($filter_by_barcodes) > 0 )
                            {
                                $this->arResult["ITEMS"] = $this->searchItems($filter_by_barcodes);
                            }
                        }

                        $this->includeComponentTemplate();
                    }
                    else
                    {
                        $this->AbortResultCache();
                    }
                }
            }
            catch(Exception $error)
            {
                echo "<p>Работа компонента прервана: " . $error->getMessage() . "</p>" ;
            }
        }
    }
}