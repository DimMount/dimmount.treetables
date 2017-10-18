<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

/**
 * User: d_kozlov
 * Date: 09.10.2017
 * Time: 21:43
 */

namespace DimMount\TreeTables;

use Bitrix\Main;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

abstract class CTDataManager extends AbstractTreeDataManager implements ICTDataManager
{
    /**
     * Удаление записей структуры осуществляется средствами MySQL
     * путем указания внешних ключей и действия ON DELETE CASCADE
     * Если true, то в таблице должны быть обязательно заданы ключи
     * CONSTRAINT `path_table_ibfk_1` FOREIGN KEY (`PARENT_ID`) REFERENCES `data_table` (`ID`) ON DELETE CASCADE,
     * CONSTRAINT `path_table_ibfk_2` FOREIGN KEY (`CHILD_ID`) REFERENCES `data_table` (`ID`) ON DELETE CASCADE
     * позволяет избежать лишних проверок и запросов, но при каскадном удалении дочерние записи будут удалены
     * средствами MySQL * без обработки событий удаления
     */
    const USE_CONSTRAINT_DELETE = false;

    /**
     * Добавление новой записи
     *
     * @param array $data - Массив с данными новой записи
     *
     * @return \Bitrix\Main\Entity\AddResult
     * @throws \Exception
     */
    public static function add(array $data)
    {
        static::handleEvent(self::EVENT_ON_AFTER_ADD, 'treeOnAfterAdd');

        return parent::add($data);
    }

    /**
     * Обработчик события после добавления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\DB\SqlQueryException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function treeOnAfterAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');
        $id = $event->getParameter('id');
        $pathEntity = static::getPathEntity();
        $pathTable = $pathEntity->getDBTableName();
        $connection = $pathEntity->getConnection();

        $parentId = (int)$data['PARENT_ID'];
        $sort = 500;
        if (array_key_exists('SORT', $data)) {
            $sort = (int)$data['SORT'];
        }
        $sql = "INSERT INTO `{$pathTable}` (
            `PARENT_ID`,
            `CHILD_ID`,
            `DEPTH_LEVEL`,
            `SORT`
        )
        SELECT
            `PARENT_ID`,
            '{$id}',
            `DEPTH_LEVEL` + 1,
            `SORT`
        FROM `{$pathTable}`
        WHERE `CHILD_ID` = '{$parentId}'
        UNION ALL
        SELECT '{$id}', '{$id}', 0, '{$sort}'";

        $connection->queryExecute($sql);

        if ($parentId > 0 && static::USE_GLOBAL_ACTIVE) {
            static::recalcGlobalActiveFlag($parentId);
        }

        return $result;
    }

    /**
     * Обновление записи
     *
     * @param mixed $primary - ИД записи
     * @param array $data    - Массив полей записи
     *
     * @return \Bitrix\Main\Entity\UpdateResult
     * @throws \Exception
     */
    public static function update($primary, array $data)
    {
        static::handleEvent(self::EVENT_ON_BEFORE_UPDATE, 'treeOnBeforeUpdate');

        static::handleEvent(self::EVENT_ON_AFTER_UPDATE, 'treeOnAfterUpdate');

        return parent::update($primary, $data);
    }

    /**
     * Обработчик события перед обновлением записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Exception
     */
    public static function treeOnBeforeUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');
        $id = $event->getParameter('id');
        $pathEntity = static::getPathEntity();

        // Получаем существующую запись
        $oldRecord = self::getRow([
            'select' => [
                'ID',
                'ACTIVE',
                'PARENT_ID',
                'SORT'
            ],
            'filter' => [
                '=ID' => $id['ID'],
            ]
        ]);

        if (empty($oldRecord)) {
            $result->addError(new Entity\EntityError(Loc::getMessage('CTTREE_ERROR_RECORD_NOT_FOUND')));

            return $result;
        }

        static::setOldRecord(self::EVENT_ON_BEFORE_UPDATE, $oldRecord);

        if (array_key_exists('PARENT_ID', $data) && (int)$data['PARENT_ID'] !== $oldRecord['PARENT_ID']) {
            $qRecursive = new Entity\Query($pathEntity);
            $qRecursive
                ->setSelect(['PARENT_ID', 'CHILD_ID'])
                ->setFilter([
                    'PARENT_ID' => $id['ID'],
                    'CHILD_ID'  => $data['PARENT_ID']
                ])
                ->setLimit(1);
            $qRecursiveResult = $qRecursive->exec();
            if ($qRecursiveResult->getSelectedRowsCount() > 0) {
                $result->addError(new Entity\EntityError(Loc::getMessage('CTTREE_ERROR_MOVING_TO_CHILD_NODE')));

                return $result;
            }
        }

        return $result;
    }

    /**
     * Обработчик события после обновления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \RuntimeException
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function treeOnAfterUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');
        $id = $event->getParameter('id');
        $oldRecord = static::getOldRecord(self::EVENT_ON_BEFORE_UPDATE);
        $pathEntity = static::getPathEntity();
        $pathTable = $pathEntity->getDBTableName();
        $connection = $pathEntity->getConnection();

        $recordId = (int)$id['ID'];
        $parentId = (int)$oldRecord['PARENT_ID'];
        $sort = (int)$oldRecord['SORT'];

        if (array_key_exists('PARENT_ID', $data) && (int)$data['PARENT_ID'] !== $parentId) {
            $qRecursive = new Entity\Query($pathEntity);
            $qRecursive
                ->setSelect(['PARENT_ID', 'CHILD_ID'])
                ->setFilter([
                    '=PARENT_ID' => $id['ID'],
                    '=CHILD_ID'  => $data['PARENT_ID']
                ])
                ->setLimit(1);
            $qRecursiveResult = $qRecursive->exec();
            if ($qRecursiveResult->getSelectedRowsCount() > 0) {
                throw new \RuntimeException(Loc::getMessage('CTTREE_ERROR_MOVING_TO_CHILD_NODE'));
            }

            $parentId = (int)$data['PARENT_ID'];

            $deleteOldSql = "DELETE A FROM `{$pathTable}` AS A
                JOIN `{$pathTable}` AS D ON A.`CHILD_ID` = D.`CHILD_ID`
                LEFT JOIN `{$pathTable}` AS X ON X.`PARENT_ID` = D.`PARENT_ID` AND X.`CHILD_ID` = A.`PARENT_ID`
                WHERE D.`PARENT_ID` = '{$recordId}'
                AND X.`PARENT_ID` IS NULL";
            $connection->queryExecute($deleteOldSql);

            $insertNewSql = "INSERT INTO `{$pathTable}` (
                `PARENT_ID`,
                `CHILD_ID`,
                `DEPTH_LEVEL`,
                `SORT`
            )
            SELECT
                TT.`PARENT_ID`,
                ST.`CHILD_ID`,
                TT.`DEPTH_LEVEL` + ST.`DEPTH_LEVEL` + 1,
                TT.`SORT`
            FROM
                `{$pathTable}` AS TT
            JOIN `{$pathTable}` AS ST
            WHERE ST.`PARENT_ID` = '{$recordId}'
                AND TT.`CHILD_ID` = '{$parentId}'";
            $connection->queryExecute($insertNewSql);
        }

        if (static::USE_GLOBAL_ACTIVE
            && ((array_key_exists('PARENT_ID', $data) && (int)$data['PARENT_ID'] !== (int)$oldRecord['PARENT_ID'])
                || (array_key_exists('ACTIVE', $data) && $data['ACTIVE'] !== (int)$oldRecord['ACTIVE']))
        ) {
            if ($parentId > 0) {
                static::recalcGlobalActiveFlag($parentId);
            } else {
                static::recalcGlobalActiveFlag($recordId);
            }
        }

        if (array_key_exists('SORT', $data) && (int)$data['SORT'] !== $sort) {
            $sort = (int)$data['SORT'];

            $updateSql = "UPDATE `{$pathTable}`
            SET `SORT` = '{$sort}'
            WHERE `PARENT_ID` = '{$recordId}'";

            $connection->queryExecute($updateSql);
        }

        return $result;
    }

    /**
     * Удаление записи
     *
     * @param mixed $primary - ИД записи
     *
     * @return \Bitrix\Main\Entity\DeleteResult
     * @throws \Exception
     */
    public static function delete($primary)
    {
        static::handleEvent(self::EVENT_ON_BEFORE_DELETE, 'treeOnBeforeDelete');
        static::handleEvent(self::EVENT_ON_DELETE, 'treeOnDelete');

        return parent::delete($primary);
    }

    /**
     * Каскадное удаление ветви вместе с потомками
     *
     * @param $primary
     *
     * @return \Bitrix\Main\Entity\DeleteResult|\Bitrix\Main\Entity\Result
     * @throws \Exception
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function deleteCascade($primary)
    {
        if (static::USE_CONSTRAINT_DELETE) {
            return parent::delete($primary);
        }

        $result = new Entity\Result();
        $pathEntity = static::getPathEntity();
        $arNodes = static::getList([
            'select'  => [
                'ID'
            ],
            'runtime' => [
                new Entity\ReferenceField(
                    'PATHS',
                    $pathEntity,
                    ['=this.ID' => 'ref.CHILD_ID'],
                    ['join_type' => 'INNER']
                ),
            ],
            'filter'  => [
                '=PATHS.PARENT_ID' => (int)$primary
            ],
            'order'   => [
                'PATHS.DEPTH_LEVEL' => 'DESC'
            ]
        ])->fetchAll();

        foreach ($arNodes as $node) {
            $deleteResult = static::delete($node['ID']);
            if (!$deleteResult->isSuccess()) {
                $result->addErrors($deleteResult->getErrors());

                return $result;
            }
        }

        return $result;
    }

    /**
     * Действия перед удалением
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\DB\SqlQueryException
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\NotImplementedException
     */
    public static function treeOnBeforeDelete(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $id = $event->getParameter('id');
        $pathEntity = static::getPathEntity();

        // Получаем существующую запись
        $oldRecord = self::getRow([
            'select' => [
                'ID',
                'ACTIVE',
                'PARENT_ID',
                'SORT'
            ],
            'filter' => [
                '=ID' => $id['ID'],
            ]
        ]);

        if (empty($oldRecord)) {
            $result->addError(new Entity\EntityError(Loc::getMessage('CTTREE_ERROR_RECORD_NOT_FOUND')));

            return $result;
        }

        $qCheckChilds = new Entity\Query($pathEntity);
        $qCheckChilds
            ->setSelect(['PARENT_ID', 'CHILD_ID'])
            ->setFilter([
                '=PARENT_ID' => $id['ID'],
                '!=CHILD_ID' => $id['ID']
            ])
            ->setLimit(1);
        $checkChildsResult = $qCheckChilds->exec();
        if ($checkChildsResult->getSelectedRowsCount() > 0) {
            $result->addError(new Main\Entity\EntityError(Loc::getMessage('CTTREE_ERROR_NODE_CONTAINS_CHILDS')));

            return $result;
        }


        return $result;
    }

    /**
     * Событие непосредственно перед удалением
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function treeOnDelete(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $id = $event->getParameter('id');
        $pathEntity = static::getPathEntity();
        $pathTable = $pathEntity->getDBTableName();
        $connection = $pathEntity->getConnection();

        if (!static::USE_CONSTRAINT_DELETE) {
            $deleteSql = "DELETE FROM `{$pathTable}` WHERE `CHILD_ID` = '{$id['ID']}'";
            $connection->queryExecute($deleteSql);
        }

        return $result;
    }

    /**
     * Установка глобального флага активности на всю ветку дерева
     *
     * @param int $nodeId идентификатор узла, от которого надо пересчитать активность
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    protected static function recalcGlobalActiveFlag($nodeId)
    {
        $pathEntity = static::getPathEntity();
        $pathTable = $pathEntity->getDBTableName();
        $selfTable = static::getEntity()->getDBTableName();
        $connection = $pathEntity->getConnection();
        $nodeId = (int)$nodeId;

        // Ищем неактивных наследников
        $arNodes = static::getList([
            'select'  => [
                'ID'
            ],
            'runtime' => [
                new Entity\ReferenceField(
                    'PATHS',
                    $pathEntity,
                    ['=this.ID' => 'ref.CHILD_ID'],
                    ['join_type' => 'INNER']
                ),
            ],
            'filter'  => [
                '=PATHS.PARENT_ID' => $nodeId,
                [
                    'LOGIC' => 'OR',
                    '=ACTIVE' => 'N',
                    '=GLOBAL_ACTIVE' => 'N'
                ]
            ],
            'order'   => [
                'PATHS.DEPTH_LEVEL' => 'DESC'
            ]
        ])->fetchAll();

        $inactiveRecords = [];
        foreach ($arNodes as $node) {
            $inactiveRecords[] = $node['ID'];
        }

        // Вся ветка делается глобально-активной
        $updateSql = "UPDATE `{$selfTable}` AS D
            JOIN `{$pathTable}` AS P ON D.`ID` = P.`CHILD_ID`
            JOIN `{$pathTable}` AS CRUMBS ON CRUMBS.`CHILD_ID` = P.`CHILD_ID` SET D.`GLOBAL_ACTIVE` = 'Y'
            WHERE P.`PARENT_ID` = '{$nodeId}'";

        $connection->queryExecute($updateSql);

        // Если есть неактивные наследники - апдейтим
        if (!empty($inactiveRecords)) {
            $inactiveRecordsString = implode(',', array_unique($inactiveRecords));
            $deactivateSql = "UPDATE `{$selfTable}` AS D
             JOIN `{$pathTable}` AS P ON D.`ID` = P.`CHILD_ID`
             JOIN `{$pathTable}` AS CRUMBS ON CRUMBS.`CHILD_ID` = P.`CHILD_ID` 
             SET D.`GLOBAL_ACTIVE` = 'N'
             WHERE P.`PARENT_ID` IN ({$inactiveRecordsString})";
            $connection->queryExecute($deactivateSql);
        }
    }
}