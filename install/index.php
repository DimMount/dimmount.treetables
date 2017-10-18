<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 *
 * Standard bitrix variables
 *
 * @global CMain     $APPLICATION
 * @global CUser     $USER
 */
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

if (class_exists('dimmount_treetables')) {
    return;
}

/**
 * Class dimmount_treetables
 */
class dimmount_treetables extends CModule
{
    /**
     * Конструктор инсталлятора модуля
     */
    public function __construct()
    {
        $arModuleVersion = [];

        include __DIR__ . '/version.php';

        $this->MODULE_ID = 'dimmount.treetables';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

        $this->MODULE_NAME = Loc::getMessage('DIMMOUNT_TREETABLES_INSTALL_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('DIMMOUNT_TREETABLES_INSTALL_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('DIMMOUNT_TREETABLES_PARTNER');
        $this->PARTNER_URI = Loc::getMessage('DIMMOUNT_TREETABLES_PARTNER_URI');
    }

    public function DoInstall()
    {
        global $APPLICATION;
        RegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile(Loc::getMessage('DIMMOUNT_TREETABLES_INSTALL_MODULE'), __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        UnRegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile(GetMessage('DIMMOUNT_TREETABLES_UNINSTALL_MODULE'), __DIR__ . '/unstep.php');
    }
}
