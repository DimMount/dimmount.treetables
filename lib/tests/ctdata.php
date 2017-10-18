<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables\Tests;

use DimMount\TreeTables;

class CTDataTable extends TreeTables\CTDataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'test_ct_data';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return array(
            'ID' => array(
                'data_type' => 'integer',
                'primary' => true,
                'autocomplete' => true,
                'title' => 'ID',
            ),
            'PARENT_ID' => array(
                'data_type' => 'integer',
                'title' => 'Parent ID',
            ),
            'NAME' => array(
                'data_type' => 'string',
                'title' => 'Name',
            ),
            'SORT' => array(
                'data_type' => 'integer',
                'title' => 'Sort',
            ),
            'ACTIVE' => array(
                'data_type' => 'boolean',
                'values' => array('N', 'Y'),
                'title' => 'Active',
            ),
            'GLOBAL_ACTIVE' => array(
                'data_type' => 'boolean',
                'values' => array('N', 'Y'),
                'title' => 'Global active',
            ),
        );
    }

    /**
     * Получение сущности таблицы со структурой дерева.
     *
     * @return \Bitrix\Main\Entity\Base
     */
    public static function getPathEntity()
    {
        return CTPathTable::getEntity();
    }
}