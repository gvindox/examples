<?php
/*Класс выгрузки объявлений из metacar в раздел "С пробегом"*/
/*API документация metacar https://api.maxposter.ru/partners-api-docs/docs.html*/

class Metacar
{
    private $login                  = 'api_techincom_noreply@metacar.ru';
    private $password               = 'password';
    private $apiKey                 = 'apiKey';
    private $apiVehicleList         = 'https://metacar.ru/api/vehicles_sale_search';
    private $apiVehicleInfo         = 'https://metacar.ru/api/vehicle_sale_info';
    private $iblockUsedCars         = 35;
    private $iblockBrandUsedCars    = 36;
    private $iblockModelsUserCars   = 37;
    private $iblockColorUsedCars    = 40;
    private $iblockAddress          = 5;
    private $debug                  = true;

    private function getRequestResult($apiUrl, $post = true, $params)
    {
        $ch = curl_init($apiUrl);
        $header[] = 'Authorization: Basic ' . $this->login . ":" . $this->password;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        if ( $post )
        {
            curl_setopt($ch, CURLOPT_POST, 1);

            if ( $params["ID"] )
            {
                curl_setopt($ch,CURLOPT_POSTFIELDS, ["vehicle_id" => $params["ID"]]);
            }
            else
                curl_setopt($ch,CURLOPT_POSTFIELDS, []);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result);
    }

    private function getVehicleInfo($objVehicle)
    {
        $requestResult = $this->getRequestResult($this->apiVehicleInfo, true, ["ID" => $objVehicle->id]);

        return $requestResult;
    }

    private function getEquipmentList()
    {
        $requestResult = $this->getRequestResult($this->apiEquipmentUrl, false);
        return $requestResult->data->vehicleEquipment;
    }

    private function getVehicleList()
    {
        $requestResult = $this->getRequestResult($this->apiVehicleList, true, []);
        return $requestResult->vehicles;
    }

    private function handlerVehicles()
    {
        $objVehicle = $this->getVehicleList();
        $arCars = [];

        foreach ( $objVehicle as $objItem )
        {
            if ( $objItem->brand == "LADA (ВАЗ)" ) $objItem->brand = "LADA";

            $name = $this->buildName($objItem);

            if ( $name )
            {
                $this->logItems($name, "/metacar/test.log", "test");
                $carId = $this->getItemByName($name, $this->iblockUsedCars);
                $arCars[] = $carId;

                if ( $carId )
                {
                    $fullVehicleData = $this->getVehicleInfo($objItem)->vehicle_info;
                    $this->setItemProps($carId, $fullVehicleData);
                    $this->setPictures($carId, $fullVehicleData);
                }
            }
        }

        $this->deleteCars($arCars);
    }

    /*заполняем машину свойствами*/
    private function setItemProps($itemId, $objData)
    {
        if ( !$itemId ) return false;

        //так как не в курсе, как называются другие типы двигателя из metacar, подгоняю по маске
        if ( stripos($objData->engine_type, "бенз") !== false )
            $objData->engine_type = "бензин";

        if ( stripos($objData->engine_type, "дизе") !== false )
            $objData->engine_type = "дизель";

        if ( stripos($objData->engine_type, "газ") !== false )
            $objData->engine_type = "газ";

        if ( $objData->kpp_type == "Механика" ) $objData->kpp_type = "МКПП";
        if ( $objData->kpp_type == "Автомат" ) $objData->kpp_type = "АКПП";

        $listTypeProps = [
            "ENGINE_TYPE"       => $objData->engine_type,
            "GEARBOX"           => $objData->kpp_type,
//            "STEER"             => $objData->steeringWheel,
            "DRIVE"             => $objData->drive_type,
            "STATUS"            => $objData->state_type,
            "CAR_OWNER"         => $objData->ownernum, //количество владельцев - список, 1, 2, 3, либо больше 3-х,
        ];

        /*получаем список значений для свойств типа список*/
        $iblockPropsEnum = CIBlockPropertyEnum::GetList(false, ["CODE" => array_keys($listTypeProps)]);
        $propsEnumUpdate = [];

        while ( $enum = $iblockPropsEnum -> Fetch() )
        {
            if ( $enum["CODE"] == "CAR_OWNER" && (int)$listTypeProps[$enum["PROPERTY_CODE"]] > 3 )
                $enum["XML_ID"] = "MORE";

            if ( strtolower($enum["VALUE"]) == strtolower($listTypeProps[$enum["PROPERTY_CODE"]]) )
                $propsEnumUpdate[$enum["PROPERTY_CODE"]] = $enum["ID"];
        }

        /*получаем описание комплектаций*/
        $htmlEquipment = "<h2>Комплектация</h2>";

        foreach ( $objData->opt_categories as $equipment )
        {
            $htmlEquipment .= "<span>{$equipment->name}</span><br>";
            $htmlEquipment .= "<ul>";

            foreach ( $equipment->univ_options as $option )
            {
                $htmlEquipment .= "<li>{$option->name}</li>";
            }

            $htmlEquipment .= "</ul>";
        }

        $videoList = [$objData->youtube_url];

        $arProps = [
            "BRAND"         => $this->getItemByName($objData->brand, $this->iblockBrandUsedCars, "brand"),
            "MODEL"         => $this->getItemByName($objData->model, $this->iblockModelsUserCars),
            "CAR_COLOR"     => $this->getColor($objData->color),
            "BODY"          => $this->getBody($objData->body_type),
            "YEAR"          => $objData->man_year,
            "MILEAGE"       => $objData->mileage,
            "ENGINE"        => $objData->volume,
            "HP"            => $objData->power, //лошадиные силы
//            "PRICEWDISC"    => $objData->priceWithDiscount,
            "PRICE"         => $objData->price,
//            "CREDIT"        => $objData->saleCredit,
//            "COMMENT"       => ['VALUE' => ['TYPE' => 'HTML', 'TEXT' => $objData->description]],
//            "VIN"           => $objData->vin,
            "DESCR"         => ['VALUE' => ['TYPE' => 'HTML', 'TEXT' => $htmlEquipment]], //описание комплектации
            "VIDEO"         => $videoList,
            "MESTO_OSMOTRA" => $this->getAddress($objData->dc->name),
        ];

        $arProps = array_merge($arProps, $propsEnumUpdate);

        $objData->updatePropsItem = $arProps;
        CIBlockElement::SetPropertyValuesEx($itemId, $this->iblockUsedCars, $arProps);
        $this->logItems($objData, "/metacar/importedCars.log", "CAR_IMPORTED");
    }

    /*загружаем картинки в авто*/
    private function setPictures($itemId, $objData)
    {
        $itemPropPicturesCode = "PHOTOS";
        $vehicleId = $objData->id;
        $objPictures = $objData->photos;
        $picProp = \CIBlockElement::GetProperty($this->iblockUsedCars, $itemId, [], ["CODE" => $itemPropPicturesCode])->Fetch();

        if ( count($objPictures) > 0 && !$picProp["VALUE"] )
        {
            $arPicUpdate = [];
            foreach ( $objPictures as $picture )
            {
                $url = $picture->image_url;

                $arPicture = \CFile::MakeFileArray($url);
                $arPicUpdate[] = ["VALUE" => $arPicture, "DESCRIPTION" => $arPicture["name"]];
            }

            \CIBlockElement::SetPropertyValuesEx(
                $itemId,
                $this->iblockUsedCars,
                [
                    $itemPropPicturesCode => $arPicUpdate
                ]
            );
        }
    }

    /*получаем тип кузова из свойства тип список*/
    private function getBody($bodyType)
    {
        $bodyType = array_shift(explode(" ", $bodyType));

        $dbBody = CIBlockPropertyEnum::GetList(false,
            [
                "IBLOCK_ID" => $this->iblockUsedCars,
                "VALUE" => $bodyType,
                "CODE" => "BODY"
            ]
        )->Fetch();

        return $dbBody["ID"];
    }

    /*получаем цвет из инфоблока по свойству перевода*/
    private function getColor($bodyColor)
    {
        $dbColor = CIBlockElement::GetList(false, [
                "IBLOCK_ID" => $this->iblockColorUsedCars,
                "ACTIVE" => "Y",
                "NAME" => $bodyColor
            ],
            false,
            ["nTopCount" => 1],
            ["ID"]
        )->Fetch();

        return $dbColor["ID"];
    }

    /*ищем элемент по имени, другой связки для использованных автомобилей нет нет при первом импорте*/
    /*возвращаем id*/
    private function getItemByName($name, $iblock, $type = "carId")
    {
        if ( !$name ) return false;

        if ( stripos($name, "LADA ") !== false && $type == "brand" ) $name = "LADA";

        $arItem = \Bitrix\Iblock\ElementTable::getList(
            [
                "filter" => [
                    "IBLOCK_ID"         => $iblock,
                    "NAME"              => $name,
                    "ACTIVE"            => "Y"
                ],
                "limit" => "1",
                "select" => ["ID"]
            ]
        )->fetchRaw();

        if ( $iblock === $this->iblockUsedCars )
            return (  $arItem["ID"] ) ?  $arItem["ID"] : $this->addItem($name, $iblock);
        else
            return $arItem["ID"];
    }

    /*добавляем автомобиль в базу с пустыми свойствами*/
    private function addItem($name, $iblock)
    {
        $fields = [
            "NAME" => $name,
            "IBLOCK_ID" => $this->iblockUsedCars,
            "ACTIVE" => "Y",
        ];

        if ( $iblock == $this->iblockUsedCars )
        {
            $props = $this->getIbockPropsList($this->iblockUsedCars);
            $propField = [];

            //заполняем обязательные свойства пустыми полями
            foreach ( $props as $prop )
            {
                $propField[] = ["CODE" => $prop["CODE"], "VALUE" => "  "];
            }

            $fields["PROPERTY_VALUES"] = [
                $propField
            ];
        }

        $el = new CIBlockElement;
        $result = $el->add($fields);

        if( $result )
        {
            return $result;
        }
        else
        {
            $error = $result->LAST_ERROR;
            echo "Ошибка добавления нового элемента: <pre>".var_export($error, true)."</pre>";
            return false;
        }
    }

    private function getIbockPropsList($iblock, $required = "Y")
    {
        $propCodeList = \Bitrix\Iblock\PropertyTable::getList(
            [
                "filter" => [
                    "IBLOCK_ID" => $iblock,
                    "ACTIVE" => "Y",
                    "IS_REQUIRED" => $required
                ],
                "select" => ["CODE", "NAME"]
            ]
        )->fetchAll();

        return $propCodeList;
    }

    /*строим имя автомобиля как сейчас записывают в админке руками*/
    private function buildName($data)
    {
        $name = false;
        /*Название выстраивается по схеме: Бренд Модель Год выпуска Цена*/
        if ( $data->brand == "LADA (ВАЗ)" ) $data->brand = "LADA";

        if ( $data->brand && $data->model && $data->man_year && $data->price )
            $name = $data->brand . " " . $data->model . " " . $data->man_year . " " . $data->price;
        else
            $this->logItems($data, "/metacar/missedByNameCars.log", "CAR_ITEM_OBJECT");

        return $name;
    }

    /*логируем пропущенные из-за имени машины*/
    private function logItems($data, $fileName, $varName)
    {
        if ( $this->debug === true )
        {
            \Bitrix\Main\Diag\Debug::dumpToFile($data, $varName, $fileName);
        }
    }

    /*приводим адреса к тем названияем, что сейчас есть в базе*/
    private function getAddress($address)
    {
        switch ($address)
        {
            case "Строгино":
                $addressDbName = "МКАД 65 км";
                break;

            default:
                $addressDbName = $address;
                break;
        }

        if ( $addressDbName )
        {
            $addressDb = $arItem = \Bitrix\Iblock\ElementTable::getList(
                [
                    "filter" => [
                        "IBLOCK_ID"         => $this->iblockAddress,
                        "NAME"              => $addressDbName,
                        "ACTIVE"            => "Y"
                    ],
                    "limit" => "1",
                    "select" => ["ID"]
                ]
            )->fetchRaw();

            return $addressDb["ID"];
        }

        return false;
    }

    /*Удаляем все автомобили, которых нет в списке выгрузки. Важно! Проверка свойства, чтобы не удалялись вручную добавленные автомобили*/
    private function deleteCars($carList)
    {
        $dbItems = CIBlockElement::GetList(
            false,
            [
                "IBLOCK_ID" => $this->iblockUsedCars,
                "ACTIVE" => "Y",
                "PROPERTY_DONT_DELETE_IMPORT" => false,
                "!ID" => $carList,
            ],
            false,
            false,
            ["ID"]
        );

        while ( $item = $dbItems->Fetch() )
        {
            CIBlockElement::Delete($item["ID"]);
        }
    }

    public function startImport()
    {
        if ( $this->debug === true )
        {
            ini_set("xdebug.overload_var_dump", "off");
            /*удаляем файлы логов*/
            array_map("unlink", glob($_SERVER["DOCUMENT_ROOT"] . "/metacar/*.log"));
        }

        $this->handlerVehicles();

        echo "Import is done";
    }
}
