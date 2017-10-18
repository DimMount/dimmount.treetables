<?php
/**
 * Copyright (c) 2017. Dmitry Kozlov. https://github.com/DimMount
 */

namespace DimMount\TreeTables;

use Bitrix\Main;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

abstract class AbstractTreeDataManager extends Entity\DataManager
{
    /**
     * Флаг использования поля глобальной активности
     */
    const USE_GLOBAL_ACTIVE = true;

    /**
     * @var array - Массив полей записи до изменения
     */
    protected static $oldRecord = [];

    /**
     * @var array - Массив флагов установки обработчиков событий
     */
    protected static $eventHandlers = [];

    /**
     * Плучение объекта подключения
     *
     * @return \Bitrix\Main\DB\Connection
     */
    public static function getConnection()
    {
        $entity = static::getEntity();

        return $entity->getConnection();
    }

    /**
     * Установка обработчика события
     *
     * @param $eventName    - Наименование события
     * @param $eventHandler - Обработчик события
     */
    protected static function handleEvent($eventName, $eventHandler)
    {
        $entity = static::getEntity();
        $eventType = $entity->getName() . $eventName;
        if (!static::$eventHandlers[$eventType]) {
            $eventManager = Main\EventManager::getInstance();
            $eventManager->addEventHandler(
                $entity->getModule(),
                $eventType,
                [
                    Entity\Base::normalizeEntityClass($entity->getNamespace() . $entity->getName()),
                    $eventHandler
                ],
                false,
                10
            );
            static::$eventHandlers[$eventType] = true;
        }
    }

    /**
     * Извлечение значений полей записи до изменения в зависимости от типа события
     *
     * @param $eventName
     *
     * @return mixed
     */
    protected static function getOldRecord($eventName)
    {
        $entity = static::getEntity();
        $eventType = $entity->getNamespace() . $entity->getName() . '::' . $eventName;

        return static::$oldRecord[$eventType];
    }

    /**
     * Сохранение значений полей записи до изменения в зависимости от типа события
     *
     * @param $eventName
     * @param $oldRecord
     */
    protected static function setOldRecord($eventName, $oldRecord)
    {
        $entity = static::getEntity();
        $eventType = $entity->getNamespace() . $entity->getName() . '::' . $eventName;
        static::$oldRecord[$eventType] = $oldRecord;
    }
}