<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables\Tests;

use Bitrix\Main;
use DimMount\TreeTables\Tests;

class NSDataManagerTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        initBitrixCore();
    }

    public static function setUpBeforeClass()
    {
        Main\Loader::includeModule('dimmount.treetables');
        $connection = Main\Application::getConnection();
        $dataTableName = Tests\NSDataTable::getEntity()->getDBTableName();

        $connection->queryExecute("TRUNCATE `{$dataTableName}`");
    }

    public function testInsertRootRecords()
    {
        for ($i = 0; $i < 100; $i++) {
            $addResult = Tests\NSDataTable::add([
                'NAME' => "Node #{$i}"
            ]);

            static::assertTrue($addResult->isSuccess(), implode('; ', $addResult->getErrorMessages()));
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testInsertRootRecords
     */
    public function testInsertChildRecords()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\NSDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 100; $i++) {
            $rs = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rs->fetch())) {
                $parentId = $arRecord['ID'];
                $addResult = Tests\NSDataTable::add([
                    'NAME'      => "Sub Node #{$i}",
                    'PARENT_ID' => $parentId
                ]);

                static::assertTrue($addResult->isSuccess(), implode('; ', $addResult->getErrorMessages()));
            }
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testInsertChildRecords
     */
    public function testMoveChildRecords()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\NSDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 100; $i++) {
            $rsData = $connection->query('SELECT ID, LEFT_MARGIN, RIGHT_MARGIN FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $rsNewParent = $connection->query("
                    SELECT ID 
                    FROM `{$tableName}` 
                    WHERE LEFT_MARGIN < {$arRecord['LEFT_MARGIN']} OR LEFT_MARGIN > {$arRecord['RIGHT_MARGIN']} 
                    ORDER BY RAND() LIMIT 1"
                );
                if (false !== ($arNewParent = $rsNewParent->fetch())) {
                    $updateResult = Tests\NSDataTable::update(
                        $arRecord['ID'], [
                            'NAME'      => "Move #{$i}",
                            'PARENT_ID' => $arNewParent['ID']
                        ]
                    );

                    static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
                }
            }
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testMoveChildRecords
     */
    public function testMoveToRoot()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\NSDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 10; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` WHERE PARENT_ID IS NOT NULL ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $updateResult = Tests\NSDataTable::update(
                    $arRecord['ID'], [
                        'NAME'      => "Move to root #{$i}",
                        'PARENT_ID' => null
                    ]
                );

                static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
            }
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testMoveToRoot
     */
    public function testResort()
    {
        $records = Tests\NSDataTable::getList([
            'select' => [
                'ID'
            ]
        ])->fetchAll();

        foreach ($records as $record) {
            $updateResult = Tests\NSDataTable::update(
                $record['ID'], [
                    'SORT' => random_int(10, 5000)
                ]
            );

            static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testResort
     */
    public function testGlobalActive()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\NSDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 50; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $updateResult = Tests\NSDataTable::update(
                    $arRecord['ID'], [
                        'NAME'   => "Set inactive #{$i}",
                        'ACTIVE' => 'N'
                    ]
                );

                static::assertTrue($updateResult->isSuccess(), implode('; ', $updateResult->getErrorMessages()));
            }
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }

    /**
     * @depends testGlobalActive
     */
    public function testDelete()
    {
        $connection = Main\Application::getConnection();
        $tableName = Tests\NSDataTable::getEntity()->getDBTableName();
        for ($i = 0; $i < 10; $i++) {
            $rsData = $connection->query('SELECT ID FROM `' . $tableName . '` ORDER BY RAND() LIMIT 1');
            if (false !== ($arRecord = $rsData->fetch())) {
                $deleteResult = Tests\NSDataTable::deleteCascade($arRecord['ID']);

                static::assertTrue($deleteResult->isSuccess(), implode('; ', $deleteResult->getErrorMessages()));
            }
        }

        $checkResult = Tests\NSDataTable::checkIntegrity();
        static::assertTrue($checkResult->isSuccess(), implode('; ', $checkResult->getErrorMessages()));
    }
}
