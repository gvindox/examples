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
class Courier_cash extends \Neti\Delivery\Classes\Profiles\Base
{
    protected static function getProfileDefaultCode()
    {
        return self::$profileCodes[1];
    }

    protected static function getProfileType()
    {
        return "courier_cash";
    }

    public static function getClassTitle()
    {
        return Loc::getMessage("COURIER_CASH_TITLE");
    }

    public static function getClassDescription()
    {
        return "";
    }
}