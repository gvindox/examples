<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class neti_delivery extends CModule
{
    public function __construct()
    {
        $this->MODULE_ID = 'neti.delivery';
        $this->MODULE_NAME = Loc::getMessage('NETI_DELIVERY_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('NETI_DELIVERY_MODULE_DESCRIPTION');
        $this->MODULE_GROUP_RIGHTS = 'N';
        $this->MODULE_VERSION = "1.0";
        $this->MODULE_VERSION_DATE = "2020-06-26";
    }

    public function doInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
    }

    public function doUninstall()
    {
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }
}
