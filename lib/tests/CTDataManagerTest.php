<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables\Tests;

use Bitrix\Main;
use DimMount\TreeTables\Tests;

class CTDataManagerTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        initBitrixCore();
    }

    public static function setUpBeforeClass()
    {
        Main\Loader::includeModule('dimmount.treetables');
        $connection = Main\Application::getConnection();
        $pathTableName = Tests\CTPathTable::getEntity()->getDBTableName();
        $dataTableName = Tests\CTDataTable::getEntity()->getDBTableName();

        $connection->queryExecute("DELETE FROM `{$pathTableName}`");
        $connection->queryExecute("DELETE FROM `{$dataTableName}`");
    }

    public function testInsertRootRecords()
    {
        for ($i = 0; $i < 100; $i++) {
            $addResult = Tests\CTDataTable::add([
                'NAME' => "Node #{$i}",
                'SORT' => random_int(10, 5000)
            ]);

            static::assertTrue($addResult->isSuccess(), implode('; ', $addResult->getErrorMessages()));
        }
    }

    /**
     * @depends testInsertRootRecords
     */
    public function testInsertChildRecords()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\CTDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 100; $i++) {
            $rs = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rs->fetch())) {
                $parentId = $arRecord['ID'];
                $addResult = Tests\CTDataTable::add([
                    'NAME'      => "Sub Node #{$i}",
                    'PARENT_ID' => $parentId,
                    'SORT'      => random_int(10, 5000)
                ]);

                static::assertTrue($addResult->isSuccess(), implode('; ', $addResult->getErrorMessages()));
            }
        }
    }

    /**
     * @depends testInsertChildRecords
     */
    public function testMoveChildRecords()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\CTDataTable::getEntity()->getDBTableName();
        $pathTableName = Tests\CTPathTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 100; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $rsPath = $connection->query("
                    SELECT CHILD_ID 
                    FROM `{$pathTableName}` WHERE CHILD_ID NOT IN (
                        SELECT CHILD_ID FROM `{$pathTableName}` WHERE PARENT_ID = '{$arRecord['ID']}'
                    ) ORDER BY RAND() LIMIT 1"
                );
                if (false !== ($arPath = $rsPath->fetch())) {
                    $updateResult = Tests\CTDataTable::update(
                        $arRecord['ID'], [
                            'NAME'      => "Move #{$i}",
                            'PARENT_ID' => $arPath['CHILD_ID']
                        ]
                    );

                    static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
                }
            }
        }
    }

    /**
     * @depends testMoveChildRecords
     */
    public function testMoveToRoot()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\CTDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 5; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` WHERE PARENT_ID IS NOT NULL ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $updateResult = Tests\CTDataTable::update(
                    $arRecord['ID'], [
                        'NAME'      => "Move to root #{$i}",
                        'PARENT_ID' => null
                    ]
                );

                static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
            }
        }
    }

    /**
     * @depends testMoveToRoot
     */
    public function testResort()
    {
        $records = Tests\CTDataTable::getList([
            'select' => [
                'ID'
            ]
        ])->fetchAll();

        foreach ($records as $record) {
            $updateResult = Tests\CTDataTable::update(
                $record['ID'], [
                    'SORT' => random_int(10, 5000)
                ]
            );

            static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
        }
    }

    /**
     * @depends testResort
     */
    public function testGlobalActive()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\CTDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 100; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $updateResult = Tests\CTDataTable::update(
                    $arRecord['ID'], [
                        'NAME'   => "Set inactive #{$i}",
                        'ACTIVE' => 'N'
                    ]
                );

                static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
            }
        }
    }

    /**
     * @depends testGlobalActive
     */
    public function testDelete()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\CTDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 10; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $deleteResult = Tests\CTDataTable::deleteCascade($arRecord['ID']);

                static::assertTrue($deleteResult->isSuccess(), implode('; ', $deleteResult->getErrorMessages()));
            }
        }
    }
}
