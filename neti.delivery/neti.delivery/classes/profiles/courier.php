<?php
namespace Neti\Delivery\Classes\Profiles;

use \Bitrix\Main\Localization\Loc;
use \Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use \Bitrix\Main\Diag\Debug;

Loc::loadMessages(__FILE__);

/**
 * Класс профиля самовывоза без наложенного платежа
 * Class Pickup
 * @package Neti\Delivery\Classes\Profiles
 */
class Courier extends \Neti\Delivery\Classes\Profiles\Base
{
    protected static function getProfileDefaultCode()
    {
        return self::$profileCodes[0];
    }

    protected static function getProfileType()
    {
        return "courier";
    }

    public static function getClassTitle()
    {
        return Loc::getMessage("COURIER_TITLE");
    }

    public static function getClassDescription()
    {
        return "";
    }
}