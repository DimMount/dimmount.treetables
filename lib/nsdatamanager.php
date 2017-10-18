<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables;

use Bitrix\Main;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class NSDataManager
 * Класс для работы с деревьями nested sets
 *
 * @package Bars46\NSTree
 */
abstract class NSDataManager extends AbstractTreeDataManager
{
    public static function setAutocommitOn()
    {
        $connection = static::getConnection();
        $sql = 'SET AUTOCOMMIT = 1';
        $connection->queryExecute($sql);
    }

    public static function setAutocommitOff()
    {
        $connection = static::getConnection();
        $sql = 'SET AUTOCOMMIT = 0';
        $connection->queryExecute($sql);
    }

    /**
     * Отмена автокоммита
     * Блокировка таблицы во избежание разрушения дерева
     *
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function lockTable()
    {
        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();
        $tableName2 = strtolower($entity->getCode());
        $sql = 'LOCK TABLES ' .
            $tableName . ' WRITE, ' .
            $tableName . ' AS ' . $tableName2 . ' WRITE, ' .
            $tableName . ' AS ' . $tableName2 . '_t1' . ' WRITE, ' .
            $tableName . ' AS ' . $tableName2 . '_t2' . ' WRITE, ' .
            $tableName . ' AS ' . $tableName2 . '_t3' . ' WRITE, ' .
            $tableName . ' AS ' . $tableName2 . '_t4' . ' WRITE, ' .
            'b_cache_tag WRITE';
        $connection->queryExecute($sql);
    }

    /**
     * Разблокирование таблицы
     * Установка автокоммита
     *
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function unlockTable()
    {
        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $sql = 'UNLOCK TABLES';
        $connection->queryExecute($sql);
    }

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
        static::handleEvent(self::EVENT_ON_BEFORE_ADD, 'treeOnBeforeAdd');
        static::handleEvent(self::EVENT_ON_AFTER_ADD, 'treeOnAfterAdd');

        return parent::add($data);
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
     * Обработчик события перед добавлением новой записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function treeOnBeforeAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');

        if (!isset($data['SORT'])) {
            $maxSort = static::getList([
                'select'  => ['MAX_SORT'],
                'runtime' => [
                    new Entity\ExpressionField('MAX_SORT', 'MAX(%s)', ['SORT'])
                ]
            ])->fetchAll();
            $result->modifyFields(['SORT' => (int)$maxSort[0]['MAX_SORT'] + 1]);
        }

        return $result;
    }

    /**
     * Обработчик события после добавления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function treeOnAfterAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');
        $id = $event->getParameter('id');

        $arParent = false;
        if ((int)$data['PARENT_ID'] > 0) {
            $parentSelectFields = [
                'ID',
                'ACTIVE',
                'DEPTH_LEVEL',
                'LEFT_MARGIN',
                'RIGHT_MARGIN'
            ];
            if (static::USE_GLOBAL_ACTIVE) {
                $parentSelectFields[] = 'GLOBAL_ACTIVE';
            }
            $arParent = self::getRow([
                'select' => $parentSelectFields,
                'filter' => [
                    '=ID' => $data['PARENT_ID'],
                ]
            ]);
        }

        // Поиск самого правого потомка
        $childSelectFields = [
            'ID',
            'RIGHT_MARGIN',
            'DEPTH_LEVEL'
        ];
        if (static::USE_GLOBAL_ACTIVE) {
            $childSelectFields[] = 'GLOBAL_ACTIVE';
        }
        $arChild = self::getRow([
            'select' => $childSelectFields,
            'filter' => [
                '=PARENT_ID' => (int)$data['PARENT_ID'] > 0 ? $data['PARENT_ID'] : false,
                '<SORT'      => $data['SORT'],
                '!ID'        => $id
            ],
            'order'  => [
                'SORT' => 'DESC'
            ]
        ]);

        if (!empty($arChild)) {
            // Найдены левые соседи
            $arUpdate = [
                'LEFT_MARGIN'  => (int)$arChild['RIGHT_MARGIN'] + 1,
                'RIGHT_MARGIN' => (int)$arChild['RIGHT_MARGIN'] + 2,
                'DEPTH_LEVEL'  => (int)$arChild['DEPTH_LEVEL'],
            ];

            if (static::USE_GLOBAL_ACTIVE) {
                // Если добавляется активный узел
                if ($data['ACTIVE'] !== 'N') {
                    // Проверяем GLOBAL_ACTIVE родителя
                    // иначе берем собственный флаг
                    if ($arParent) {
                        // Наследование активности от родителя
                        $arUpdate['GLOBAL_ACTIVE'] = $arParent['ACTIVE'] === 'Y' ? 'Y' : 'N';
                    } else {
                        // Если нет родителя, то используем собственный флаг активности
                        $arUpdate['GLOBAL_ACTIVE'] = 'Y';
                    }
                } else {
                    $arUpdate['GLOBAL_ACTIVE'] = 'N';
                }
            }
        } else {
            // Если единственный или самый левый узел в дереве
            $arUpdate = [
                'LEFT_MARGIN'  => 1,
                'RIGHT_MARGIN' => 2,
                'DEPTH_LEVEL'  => 1,
            ];
            if (static::USE_GLOBAL_ACTIVE) {
                $arUpdate['GLOBAL_ACTIVE'] = $data['ACTIVE'] !== 'N' ? 'Y' : 'N';
            }

            // Если есть родитель, то берется его левый ключ
            if ($arParent) {
                $arUpdate = [
                    'LEFT_MARGIN'  => (int)$arParent['LEFT_MARGIN'] + 1,
                    'RIGHT_MARGIN' => (int)$arParent['LEFT_MARGIN'] + 2,
                    'DEPTH_LEVEL'  => (int)$arParent['DEPTH_LEVEL'] + 1,
                ];
                if (static::USE_GLOBAL_ACTIVE) {
                    $arUpdate['GLOBAL_ACTIVE'] = ($arParent['GLOBAL_ACTIVE'] === 'Y') && ($data['ACTIVE'] !== 'N') ? 'Y' : 'N';
                }
            }
        }

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        $connection->queryExecute('
            UPDATE `' . $tableName . '` SET
                LEFT_MARGIN = ' . $arUpdate['LEFT_MARGIN'] . '
                ,RIGHT_MARGIN = ' . $arUpdate['RIGHT_MARGIN'] . '
                ,DEPTH_LEVEL = ' . $arUpdate['DEPTH_LEVEL'] . (static::USE_GLOBAL_ACTIVE ? "
                ,GLOBAL_ACTIVE = '" . $arUpdate['GLOBAL_ACTIVE'] . "'" : '') . '
            WHERE
                ID = ' . $id . '
        ');

        $connection->queryExecute('
            UPDATE `' . $tableName . '` SET
                LEFT_MARGIN = LEFT_MARGIN + 2
                ,RIGHT_MARGIN = RIGHT_MARGIN + 2
            WHERE
                LEFT_MARGIN >= ' . $arUpdate['LEFT_MARGIN'] . '
                AND ID <> ' . $id . '
        ');
        if ($arParent) {
            $connection->queryExecute('
                UPDATE `' . $tableName . '` SET
                    RIGHT_MARGIN = RIGHT_MARGIN + 2
                WHERE
                    LEFT_MARGIN <= ' . $arParent['LEFT_MARGIN'] . '
                    AND RIGHT_MARGIN >= ' . $arParent['RIGHT_MARGIN'] . '
            ');
        }

        return $result;
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

        $oldSelectFields = [
            'ID',
            'ACTIVE',
            'DEPTH_LEVEL',
            'LEFT_MARGIN',
            'RIGHT_MARGIN',
            'SORT',
            'PARENT_ID'
        ];
        if (static::USE_GLOBAL_ACTIVE) {
            $oldSelectFields[] = 'GLOBAL_ACTIVE';
        }
        $oldRecord = self::getRow([
            'select' => $oldSelectFields,
            'filter' => [
                '=ID' => $id['ID'],
            ]
        ]);
        if (empty($oldRecord)) {
            $result->addError(new Entity\EntityError(Loc::getMessage('NSTREE_ERROR_RECORD_NOT_FOUND')));

            return $result;
        }

        static::setOldRecord(self::EVENT_ON_BEFORE_UPDATE, $oldRecord);

        if (array_key_exists('PARENT_ID', $data) && (int)$data['PARENT_ID'] !== (int)$oldRecord['PARENT_ID']) {
            $recursiveCheck = self::getRow([
                'select' => [
                    'ID'
                ],
                'filter' => [
                    '=ID'           => $data['PARENT_ID'],
                    '>=LEFT_MARGIN' => $oldRecord['LEFT_MARGIN'],
                    '<=LEFT_MARGIN' => $oldRecord['RIGHT_MARGIN']
                ]
            ]);
            if (!empty($recursiveCheck)) {
                $result->addError(new Entity\EntityError(Loc::getMessage('NSTREE_ERROR_MOVING_TO_CHILD_NODE')));

                return $result;
            }

            // При смене родителя устанавливаем максимальное значение сортировки,
            // чтобы в новой ветке элемент оказался в конце
            $maxSort = static::getList(
                [
                    'select'  => ['MAX_SORT'],
                    'runtime' => [
                        new Entity\ExpressionField('MAX_SORT', 'MAX(%s)', ['SORT'])
                    ]
                ]
            )->fetchAll();
            $result->modifyFields(['SORT' => (int)$maxSort[0]['MAX_SORT'] + 1]);
        }

        return $result;
    }

    /**
     * Обработчик события после обновления записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function treeOnAfterUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $data = $event->getParameter('fields');
        $id = $event->getParameter('id');
        $oldRecord = static::getOldRecord(self::EVENT_ON_BEFORE_UPDATE);

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        //Move inside the tree
        if (
            (array_key_exists('SORT', $data) && (int)$data['SORT'] !== (int)$oldRecord['SORT'])
            ||
            (array_key_exists('PARENT_ID', $data) && (int)$data['PARENT_ID'] !== (int)$oldRecord['PARENT_ID'])
        ) {
            // Сначала производится "удаление" ветви из структуры дерева
            $distance = (int)$oldRecord['RIGHT_MARGIN'] - (int)$oldRecord['LEFT_MARGIN'] + 1;
            $connection->queryExecute('
				UPDATE `' . $tableName . '` SET
					LEFT_MARGIN = -LEFT_MARGIN
					,RIGHT_MARGIN = -RIGHT_MARGIN
				WHERE
					LEFT_MARGIN >= ' . (int)$oldRecord['LEFT_MARGIN'] . '
					AND LEFT_MARGIN <= ' . (int)$oldRecord['RIGHT_MARGIN'] . '
			');
            $connection->queryExecute('
				UPDATE `' . $tableName . '` SET
					RIGHT_MARGIN = RIGHT_MARGIN - ' . $distance . '
				WHERE
					RIGHT_MARGIN > ' . $oldRecord['RIGHT_MARGIN'] . '
			');
            $connection->queryExecute('
				UPDATE `' . $tableName . '` SET
					LEFT_MARGIN = LEFT_MARGIN - ' . $distance . '
				WHERE
					LEFT_MARGIN > ' . $oldRecord['LEFT_MARGIN'] . '
			');

            // Далее производится вставка в структуру дерева как и при вставке нового узла

            $parentID = array_key_exists('PARENT_ID', $data) ? (int)$data['PARENT_ID'] : (int)$oldRecord['PARENT_ID'];
            $sort = array_key_exists('SORT', $data) ? (int)$data['SORT'] : (int)$oldRecord['SORT'];

            $arParents = [];
            $parentsSelectFields = [
                'ID',
                'ACTIVE',
                'DEPTH_LEVEL',
                'LEFT_MARGIN',
                'RIGHT_MARGIN'
            ];
            if (static::USE_GLOBAL_ACTIVE) {
                $parentsSelectFields[] = 'GLOBAL_ACTIVE';
            }
            $rsParents = static::getList([
                'select' => $parentsSelectFields,
                'filter' => [
                    '@ID' => [
                        (int)$oldRecord['PARENT_ID'],
                        $parentID
                    ]
                ]
            ]);
            while (false !== ($arParent = $rsParents->fetch())) {
                $arParents[$arParent['ID']] = $arParent;
            }
            // Поиск самого правого потомка родителя
            $rsChild = static::getList([
                'select' => [
                    'ID',
                    'RIGHT_MARGIN',
                    'DEPTH_LEVEL'
                ],
                'filter' => [
                    '=PARENT_ID' => $parentID > 0 ? $parentID : false,
                    '<SORT'      => $sort,
                    '!ID'        => $id['ID']
                ],
                'order'  => [
                    'SORT' => 'DESC'
                ]
            ]);
            if (false !== ($arChild = $rsChild->fetch())) {
                // Найдены соседи слева
                $arUpdate = [
                    'LEFT_MARGIN' => (int)$arChild['RIGHT_MARGIN'] + 1,
                    'DEPTH_LEVEL' => (int)$arChild['DEPTH_LEVEL'],
                ];
            } else {
                // Если единственный или самый левый узел в дереве
                $arUpdate = [
                    'LEFT_MARGIN' => 1,
                    'DEPTH_LEVEL' => 1,
                ];

                // Если есть родитель, то берется его левый ключ
                if (isset($arParents[$parentID]) && $arParents[$parentID]) {
                    $arUpdate = [
                        'LEFT_MARGIN' => (int)$arParents[$parentID]['LEFT_MARGIN'] + 1,
                        'DEPTH_LEVEL' => (int)$arParents[$parentID]['DEPTH_LEVEL'] + 1,
                    ];
                }
            }

            $moveDistance = (int)$oldRecord['LEFT_MARGIN'] - $arUpdate['LEFT_MARGIN'];

            $connection->queryExecute('
				UPDATE `' . $tableName . '` SET
					LEFT_MARGIN = LEFT_MARGIN + ' . $distance . '
					,RIGHT_MARGIN = RIGHT_MARGIN + ' . $distance . '
				WHERE
					LEFT_MARGIN >= ' . $arUpdate['LEFT_MARGIN'] . '
			');
            $connection->queryExecute('
				UPDATE `' . $tableName . '` SET
					LEFT_MARGIN = -LEFT_MARGIN - ' . $moveDistance . '
					,RIGHT_MARGIN = -RIGHT_MARGIN - ' . $moveDistance . '
					' . ($arUpdate['DEPTH_LEVEL'] !== (int)$oldRecord['DEPTH_LEVEL'] ? ',DEPTH_LEVEL = DEPTH_LEVEL - ' . ($oldRecord['DEPTH_LEVEL'] - $arUpdate['DEPTH_LEVEL']) : '') . '
				WHERE
					LEFT_MARGIN <= ' . (-(int)$oldRecord['LEFT_MARGIN']) . '
					AND LEFT_MARGIN >= ' . (-(int)$oldRecord['RIGHT_MARGIN']) . '
			');

            if (isset($arParents[$parentID])) {
                $connection->queryExecute('
					UPDATE `' . $tableName . '` SET
						RIGHT_MARGIN = RIGHT_MARGIN + ' . $distance . '
					WHERE
						LEFT_MARGIN <= ' . $arParents[$parentID]['LEFT_MARGIN'] . '
						AND RIGHT_MARGIN >= ' . $arParents[$parentID]['RIGHT_MARGIN'] . '
				');
            }
        }

        // Проверка смены родителя
        if (static::USE_GLOBAL_ACTIVE
            && array_key_exists('PARENT_ID', $data)
            && (int)$data['PARENT_ID'] !== (int)$oldRecord['PARENT_ID']
        ) {
            $arSection = static::getRow([
                'select' => [
                    'ID',
                    'PARENT_ID',
                    'ACTIVE',
                    'GLOBAL_ACTIVE',
                    'LEFT_MARGIN',
                    'RIGHT_MARGIN'
                ],
                'filter' => [
                    '=ID' => $id
                ]
            ]);

            $arParent = static::getRow([
                'select' => [
                    'ID',
                    'GLOBAL_ACTIVE',
                ],
                'filter' => [
                    '=ID' => (int)$data['PARENT_ID']
                ]
            ]);
            // Если у родителя GLOBAL_ACTIVE=N или сам элемент не активен то тоже устанавливаем GLOBAL_ACTIVE=N
            if (($arParent && $arParent['GLOBAL_ACTIVE'] === 'N') || ($data['ACTIVE'] === 'N')) {
                $connection->queryExecute('
					UPDATE `' . $tableName . "` SET
						GLOBAL_ACTIVE = 'N'
					WHERE
						LEFT_MARGIN >= " . (int)$arSection['LEFT_MARGIN'] . '
						AND RIGHT_MARGIN <= ' . (int)$arSection['RIGHT_MARGIN'] . '
				');
            } elseif ($arSection['ACTIVE'] === 'N' && $data['ACTIVE'] === 'Y') {
                static::recalcGlobalActiveFlag($arSection);
            } elseif (
                (!$arParent || $arParent['GLOBAL_ACTIVE'] === 'Y')
                && $arSection['GLOBAL_ACTIVE'] === 'N'
                && ($arSection['ACTIVE'] === 'Y' || $data['ACTIVE'] === 'Y')
            ) {
                static::recalcGlobalActiveFlag($arSection);
            }
        } // Если родитель не менялся, но изменился флаг активности
        elseif (array_key_exists('ACTIVE', $data) && $data['ACTIVE'] !== $oldRecord['ACTIVE']) {
            // Все потомки делаются глобально неактивными
            if ($data['ACTIVE'] === 'N') {
                $connection->queryExecute('
					UPDATE `' . $tableName . "` SET
						GLOBAL_ACTIVE = 'N'
					WHERE
						LEFT_MARGIN >= " . (int)$oldRecord['LEFT_MARGIN'] . '
						AND RIGHT_MARGIN <= ' . (int)$oldRecord['RIGHT_MARGIN'] . '
				');
            } else {
                // Проверка активности родителя
                $arParent = static::getRow([
                    'select' => [
                        'ID',
                        'GLOBAL_ACTIVE'
                    ],
                    'filter' => [
                        '=ID' => (int)$oldRecord['PARENT_ID']
                    ]
                ]);
                // Родитель активен, а потомок изменился. Производится пересчет глобальной активности
                if (!$arParent || $arParent['GLOBAL_ACTIVE'] === 'Y') {
                    static::recalcGlobalActiveFlag($oldRecord);
                }
            }
        }

        return $result;
    }

    /**
     * Установка глобального флага активности на всю ветку дерева
     *
     * @param array[] $arSection Массив с данными узла дерева
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function recalcGlobalActiveFlag($arSection)
    {
        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        // Все потомки делаются глобально активными
        $connection->queryExecute('
			UPDATE `' . $tableName . "` SET
				GLOBAL_ACTIVE = 'Y'
			WHERE
				LEFT_MARGIN >= " . (int)$arSection['LEFT_MARGIN'] . '
				AND RIGHT_MARGIN <= ' . (int)$arSection['RIGHT_MARGIN'] . '
		');
        // Выбор неактивных
        $arUpdate = [];
        $prev_right = 0;
        $rsChildren = static::getList([
            'select' => [
                'ID',
                'LEFT_MARGIN',
                'RIGHT_MARGIN'
            ],
            'filter' => [
                '>=LEFT_MARGIN'  => (int)$arSection['LEFT_MARGIN'],
                '<=RIGHT_MARGIN' => (int)$arSection['RIGHT_MARGIN'],
                '=ACTIVE'        => 'N'
            ],
            'order'  => [
                'LEFT_MARGIN' => 'ASC'
            ]
        ]);
        while (false !== ($arChild = $rsChildren->fetch())) {
            if ($arChild['RIGHT_MARGIN'] > $prev_right) {
                $prev_right = $arChild['RIGHT_MARGIN'];
                $arUpdate[] = '(LEFT_MARGIN >= ' . $arChild['LEFT_MARGIN'] . ' AND RIGHT_MARGIN <= ' . $arChild['RIGHT_MARGIN'] . ")\n";
            }
        }
        if (count($arUpdate) > 0) {
            $connection->queryExecute('
				UPDATE `' . $tableName . "` SET
					GLOBAL_ACTIVE = 'N'
				WHERE
					" . implode(' OR ', $arUpdate) . '
			');
        }
    }

    /**
     * Обработчик события перед удалением записи
     * Проверяется существование записи и наличие подчиненных узлов
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function treeOnBeforeDelete(Entity\Event $event) {
        $result = new Entity\EventResult;
        $id = $event->getParameter('id');

        $oldRecord = self::getRow([
            'select' => [
                'LEFT_MARGIN',
                'RIGHT_MARGIN',
            ],
            'filter' => [
                '=ID' => $id,
            ]
        ]);
        if (empty($oldRecord)) {
            $result->addError(new Entity\EntityError(Loc::getMessage('NSTREE_ERROR_RECORD_NOT_FOUND')));

            return $result;
        }

        static::setOldRecord(self::EVENT_ON_BEFORE_DELETE, $oldRecord);

        $childNodes = static::getList([
            'select'  => ['CNT'],
            'runtime' => [
                new Entity\ExpressionField('CNT', 'COUNT(*)')
            ],
            'filter'  => [
                '>LEFT_MARGIN'  => $oldRecord['LEFT_MARGIN'],
                '<RIGHT_MARGIN' => $oldRecord['RIGHT_MARGIN']
            ]
        ])->fetch();
        if ($childNodes['CNT'] > 0) {
            $result->addError(new Entity\EntityError(Loc::getMessage('NSTREE_ERROR_MOVING_TO_CHILD_NODE')));

            return $result;
        }

        return $result;
    }

    /**
     * Обработчик события непосредственно перед удалением записи
     *
     * @param \Bitrix\Main\Entity\Event $event
     *
     * @return \Bitrix\Main\Entity\EventResult
     * @throws \Bitrix\Main\DB\SqlQueryException
     */
    public static function treeOnDelete(Entity\Event $event)
    {
        $result = new Entity\EventResult;
        $oldRecord = static::getOldRecord(self::EVENT_ON_BEFORE_DELETE);

        $entity = static::getEntity();
        $connection = $entity->getConnection();
        $tableName = $entity->getDBTableName();

        $connection->queryExecute('
			UPDATE `' . $tableName . '` 
			SET
				RIGHT_MARGIN = RIGHT_MARGIN - 2
			WHERE
				RIGHT_MARGIN > ' . $oldRecord['RIGHT_MARGIN'] . '
		');

        $connection->queryExecute('
			UPDATE `' . $tableName . '` 
			SET
				LEFT_MARGIN = LEFT_MARGIN - 2
			WHERE
			    LEFT_MARGIN > ' . $oldRecord['LEFT_MARGIN'] . '
		');

        return $result;
    }

    /**
     * Проверка целостности дерева
     *
     * @throws \Exception
     */
    public static function checkIntegrity()
    {
        $result = new Main\Result;
        // Правый ключ всегда больше левого
        $check1 = static::getRow([
            'select' => [
                'ID'
            ],
            'filter' => [
                '>=LEFT_MARGIN' => new Main\DB\SqlExpression('?#', 'RIGHT_MARGIN')
            ]
        ]);
        if (!empty($check1)) {
            $result->addError(new Main\Error(Loc::getMessage('NSTREE_ERROR_LEFT_MORE_THAN_RIGHT') . ', ID:' . $check1['ID']));
        }

        // Минимальный левый ключ всегда = 1
        // Максимальный правый ключ всегда равен кол-во * 2
        $check2 = static::getRow([
            'select' => [
                new Entity\ExpressionField('CNT', 'COUNT(%s)', ['ID']),
                new Entity\ExpressionField('MIN_LEFT', 'MIN(%s)', ['LEFT_MARGIN']),
                new Entity\ExpressionField('MAX_RIGHT', 'MAX(%s)', ['RIGHT_MARGIN'])
            ]
        ]);
        if ((int)$check2['MIN_LEFT'] !== 1 || (int)$check2['MAX_RIGHT'] !== (int)$check2['CNT'] * 2) {
            $result->addError(new Main\Error(Loc::getMessage('NSTREE_ERROR_WRONG_CHECKSUM')));
        }

        // Разность между правым и левым ключом всегда нечетная
        $check3 = static::getRow([
            'select'  => [
                'ID'
            ],
            'runtime' => [
                new Entity\ExpressionField('OSTATOK', 'MOD((%s - %s), 2)', ['RIGHT_MARGIN', 'LEFT_MARGIN'])
            ],
            'filter'  => [
                '=OSTATOK' => new Main\DB\SqlExpression('?i', 0)
            ]
        ]);
        if (!empty($check3)) {
            $result->addError(new Main\Error(Loc::getMessage('NSTREE_ERROR_WRONG_PARITY_LEFT_RIGHT') . ', ID:' . $check3['ID']));
        }

        // Если уровень узла нечетное число то тогда левый ключ ВСЕГДА нечетное число, то же самое и для четных чисел
        $check4 = static::getRow([
            'select'  => [
                'ID'
            ],
            'runtime' => [
                new Entity\ExpressionField('OSTATOK', 'MOD((%s - %s - 2), 2)', ['LEFT_MARGIN', 'DEPTH_LEVEL'])
            ],
            'filter'  => [
                '=OSTATOK' => new Main\DB\SqlExpression('?i', 1)
            ]
        ]);
        if (!empty($check4)) {
            $result->addError(new Main\Error(Loc::getMessage('NSTREE_ERROR_WRONG_PARITY_LEFT_LEVEL') . ', ID:' . $check4['ID']));
        }

        // Ключи ВСЕГДА уникальны, вне зависимости от того правый он или левый;
        $entity = static::getEntity();
        $check5 = static::getRow([
            'select'  => [
                new Entity\ExpressionField('CNT', 'COUNT(*)')
            ],
            'runtime' => [
                new Entity\ReferenceField(
                    'T1',
                    substr($entity->getNamespace() . $entity->getName(), 1),
                    [
                        '=this.LEFT_MARGIN' => 'ref.LEFT_MARGIN',
                        '!=this.ID'         => 'ref.ID'
                    ]
                ),
                new Entity\ReferenceField(
                    'T2',
                    substr($entity->getNamespace() . $entity->getName(), 1),
                    [
                        '=this.LEFT_MARGIN' => 'ref.RIGHT_MARGIN'
                    ]
                ),
                new Entity\ReferenceField(
                    'T3',
                    substr($entity->getNamespace() . $entity->getName(), 1),
                    [
                        '=this.RIGHT_MARGIN' => 'ref.RIGHT_MARGIN',
                        '!=this.ID'          => 'ref.ID'
                    ]
                ),
                new Entity\ReferenceField(
                    'T4',
                    substr($entity->getNamespace() . $entity->getName(), 1),
                    [
                        '=this.RIGHT_MARGIN' => 'ref.LEFT_MARGIN'
                    ]
                )
            ],
            'filter'  => [
                [
                    'LOGIC' => 'OR',
                    [
                        '!=T1.ID' => false
                    ],
                    [
                        '!=T2.ID' => false
                    ],
                    [
                        '!=T3.ID' => false
                    ],
                    [
                        '!=T4.ID' => false
                    ]
                ]
            ]
        ]);
        if ((int)$check5['CNT'] > 0) {
            $result->addError(new Main\Error(Loc::getMessage('NSTREE_ERROR_UNIQUE_KEYS') . ', ' . $check5['CNT']));
        }

        return $result;
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
        $result = new Entity\Result();
        $nodeRoot = self::getRow([
            'select' => [
                'LEFT_MARGIN',
                'RIGHT_MARGIN',
            ],
            'filter' => [
                '=ID' => (int)$primary,
            ]
        ]);

        if (!empty($nodeRoot)) {
            $arNodes = static::getList([
                'select' => [
                    'ID'
                ],
                'filter' => [
                    '>=LEFT_MARGIN'  => (int)$nodeRoot['LEFT_MARGIN'],
                    '<=RIGHT_MARGIN' => (int)$nodeRoot['RIGHT_MARGIN']
                ],
                'order'  => [
                    'LEFT_MARGIN' => 'DESC'
                ]
            ])->fetchAll();

            foreach ($arNodes as $node) {
                $deleteResult = static::delete($node['ID']);
                if (!$deleteResult->isSuccess()) {
                    $result->addErrors($deleteResult->getErrors());

                    return $result;
                }
            }
        } else {
            $result->addError(new Entity\EntityError(Loc::getMessage('NSTREE_ERROR_RECORD_NOT_FOUND')));

            return $result;
        }

        return $result;
    }
}