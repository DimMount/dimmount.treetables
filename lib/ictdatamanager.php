<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables;

interface ICTDataManager
{
    /**
     * Получение сущности таблицы со структурой дерева.
     *
     * @return \Bitrix\Main\Entity\Base
     */
    public static function getPathEntity();
}