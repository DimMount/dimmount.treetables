<?
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 *
 * Standard bitrix variables
 *
 * @global CMain     $APPLICATION
 * @global CUser     $USER
 */
use Bitrix\Main\Localization\Loc;

if(!check_bitrix_sessid()) {
    return;
}
$adminMessage = new CAdminMessage(Loc::getMessage('MOD_UNINST_OK'));
$adminMessage->Show();
?>
<form action="<?echo $APPLICATION->GetCurPage()?>">
	<input type="hidden" name="lang" value="<?echo LANG?>">
	<input type="submit" name="" value="<?echo Loc::getMessage('MOD_BACK')?>">
<form>
