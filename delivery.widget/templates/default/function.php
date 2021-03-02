<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

if ( !function_exists('findParentBXB') )
{
    function findParentBXB($profiles)
    {
        if ( $profiles['CODE'] == 'boxberry' )
        {
            return $profiles['ID'];
        }
    }
}