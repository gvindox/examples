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
class Pickup extends \Neti\Delivery\Classes\Profiles\Base
{
    protected static function getProfileDefaultCode()
    {
        return self::$profileCodes[2];
    }

    protected static function getProfileType()
    {
        return "pickup";
    }

    public static function getClassTitle()
    {
        return Loc::getMessage("PICKUP_PROFILE_TITLE");
    }

    public static function getClassDescription()
    {
        return "";
    }
}