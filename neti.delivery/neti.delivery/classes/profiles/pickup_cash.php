<?php
namespace Neti\Delivery\Classes\Profiles;

use \Bitrix\Main\Localization\Loc;
use \Neti\Delivery\Classes\BoxBerry\Api as BoxBerry_Api;
use \Bitrix\Main\Diag\Debug;

/**
 * Класс профиля самовывоза с наложенным платежом
 * Class Pickup_cash
 * @package Neti\Delivery\Classes\Profiles
 */
class Pickup_cash extends \Neti\Delivery\Classes\Profiles\Base
{
    protected static function getProfileDefaultCode()
    {
        return self::$profileCodes[3];
    }

    protected static function getProfileType()
    {
        return "pickup_cash";
    }

    public static function getClassTitle()
    {
        return Loc::getMessage("PICKUP_CASH_PROFILE_TITLE");
    }

    public static function getClassDescription()
    {
        return "";
    }
}