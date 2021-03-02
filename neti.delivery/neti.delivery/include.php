<?php
defined('B_PROLOG_INCLUDED') and (B_PROLOG_INCLUDED === true) or die();

Bitrix\Main\Loader::registerAutoLoadClasses(
    'neti.delivery',
    [
        'Neti\Delivery\Classes\DeliveryHandler' => 'classes/delivery_handler.php',
        'Neti\Delivery\Classes\BoxBerry\Api' => 'classes/boxberry/api.php',
        'Neti\Delivery\Classes\Profiles\Pickup_cash' => 'classes/profiles/pickup_cash.php',
        'Neti\Delivery\Classes\Profiles\Pickup' => 'classes/profiles/pickup.php',
        'Neti\Delivery\Classes\Profiles\Base' => 'classes/profiles/Base.php',
        'Neti\Delivery\Classes\Profiles\Courier' => 'classes/profiles/courier.php',
        'Neti\Delivery\Classes\Profiles\Courier_cash' => 'classes/profiles/courier_cash.php',
        'Neti\Delivery\Classes\Store' => 'classes/Store.php',
        'Neti\Delivery\Classes\Shipment' => 'classes/Shipment.php',
        'Neti\Delivery\Classes\Order' => 'classes/Order.php',
        'Neti\Delivery\Classes\Delivery' => 'classes/Delivery.php',
        'Neti\Delivery\Classes\BoxBerry\Data' => 'classes/boxberry/Data.php',
    ]
);