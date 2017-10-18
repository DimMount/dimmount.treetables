<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables\Tests;

use Bitrix\Main;

class CTPathTable extends Main\Entity\DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'test_ct_path';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return array(
            'PARENT_ID' => array(
                'data_type' => 'integer',
                'primary' => true,
                'title' => 'Parent ID',
            ),
            'CHILD_ID' => array(
                'data_type' => 'integer',
                'title' => 'Child ID',
            ),
            'DEPTH_LEVEL' => array(
                'data_type' => 'integer',
                'title' => 'Depth Level',
            ),
            'SORT' => array(
                'data_type' => 'integer',
                'title' => 'Sort',
            )
        );
    }
}