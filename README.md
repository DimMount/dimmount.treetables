# Модуль dimmount.treetables

Модуль предназначен для работы с иерархическими структурами **Nested Sets** и **Closure Table** в БД MySQL с использованием API ORM Битрикс.

Модуль содержит абстрактные классы:
<ul>
<li><b>NSDataManager</b> для работы со структурами "Nested Sets"</li>
<li><b>CTDataManager</b> для работы со структурами "Closure Tables"</li>
</ul>
  
## NSDataManager

Для работы с деревом необходимо создать класс-наследник от NSDataManager.

Метод getMap() обязательно должен содержать поля:
<ul>
<li>ID - Идентификатор записи, первичный ключ</li>
<li>PARENT_ID - Идентификатор родительской записи</li>
<li>LEFT_MARGIN - левый ключ</li>
<li>RIGHT_MARGIN - правый ключ</li>
<li>DEPTH_LEVEL - уровень вложенности</li>
<li>ACTIVE - флаг активности</li>
<li>GLOBAL_ACTIVE - флаг активности всего узла</li>
<li>SORT - сортировка</li>
</ul>

Поле "GLOBAL_ACTIVE" является опциональным. Если не планируется использовать флаг активности узла, то в классе-наследнике необходимо задать константу:
```php
const USE_GLOBAL_ACTIVE = false;
```

Пример класса можно посмотреть в файле "lib/tests/nsdata.php". Пример структуры таблицы:
```mysql
CREATE TABLE `test_ns_data` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `PARENT_ID` int(11) DEFAULT NULL,
  `LEFT_MARGIN` int(11) NOT NULL,
  `RIGHT_MARGIN` int(11) NOT NULL,
  `DEPTH_LEVEL` int(11) DEFAULT NULL,
  `ACTIVE` char(1) COLLATE utf8_unicode_ci DEFAULT 'Y',
  `GLOBAL_ACTIVE` char(1) COLLATE utf8_unicode_ci DEFAULT 'Y',
  `SORT` int(11) NOT NULL,
  `NAME` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `LEFT_MARGIN` (`LEFT_MARGIN`,`RIGHT_MARGIN`),
  KEY `RIGHT_MARGIN` (`RIGHT_MARGIN`,`LEFT_MARGIN`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
```

### Примеры
#### Добавление новой записи в корень дерева
```php
NSDataTable::add(
    array(
        'NAME' => 'ROOT ROW'
    )
);
```

#### Добавление записи в существующую ветку
```php
NSDataTable::add(
    array(
        'PARENT_ID' => $parent_node_id,
        'NAME' => 'CHILD ROW'
    )
);
```

#### Перемещение записи или целой ветки в новую ветку
```php
NSDataTable::update(
    $id,
    array(
        'PARENT_ID' => $new_parent_node_id
    )
);
```

#### Получение всего упорядоченного дерева, начиная от корня
```php
$res = NSDataTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

#### Получение только корневых элементов
```php
$res = NSDataTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '=DEPTH_LEVEL' => 1
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

#### Получение всех потомков конкретной ветки дерева
```php
$node = NSDataTable::getRow(
    array(
        'select' => array(
            'LEFT_MARGIN',
            'RIGHT_MARGIN'
        ),
        'filter' => array(
            '=ID' => $node_id
        )
    )
);
$res = NSDataTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '>LEFT_MARGIN' => $node['LEFT_MARGIN'],
            '<RIGHT_MARGIN' => $node['RIGHT_MARGIN']
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

#### Получение всех предков конкретной ветки дерева
```php
$node = NSDataTable::getRow(
    array(
        'select' => array(
            'LEFT_MARGIN',
            'RIGHT_MARGIN'
        ),
        'filter' => array(
            '=ID' => $node_id
        )
    )
);
$res = NSDataTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '<LEFT_MARGIN' => $node['LEFT_MARGIN'],
            '>RIGHT_MARGIN' => $node['RIGHT_MARGIN']
        ),
        'order' => array(
            'LEFT_MARGIN' => 'ASC'
        )
    )
);
```

#### Удаление узла
```php
$deleteResult = NSDataTable::delete($nodeId);
```
перед удалением проверяется наличие дочерних записей. Если они есть, то выдается ошибка

#### Удаление всей ветки
```php
$deleteResult = NSDataTable::deleteCascade($nodeId);
```
Все узлы удаляются по очереди в цикле, начиная с самых последних.

###№ Транзакции

Во избежание разрушения структуры дерева при совместном доступе можно блокировать таблицу на запись и откатывать изменения при возникновении ошибок.
Для этого служат методы **lockTable()** и **unlockTable()**

```php
$connection = Bitrix\Main\Application::getConnection();
NSDataTable::lockTable();
try {
    NSDataTable::add(
        array(
            'PARENT_ID' => $parent_node_id,
            'NAME' => 'CHILD ROW'
        )
    );
    $connection->commitTransaction();
} catch (\Exception $e) {
    $connection->rollbackTransaction();
    NSDataTable::unlockTable();
    echo($e->getMessage() . "\n");
}
```

## CTDataManager

Для хранения деревьев "Closure Tables" используются две таблицы. Одна таблица для хранения данных, другая - для хранения связей.
В отличии от "Nested Sets" в "Closure Tables" поддерживается ссылочная целостность.

### Таблица для хранения данных

Необходимо создать класс-наследник от CTDataManager.

Метод getMap() обязательно должен содержать поля:
<ul>
<li>ID - Идентификатор записи, первичный ключ</li>
<li>PARENT_ID - Идентификатор родительской записи</li>
<li>ACTIVE - флаг активности</li>
<li>GLOBAL_ACTIVE - флаг активности всего узла</li>
<li>SORT - сортировка</li>
</ul>

Поле "GLOBAL_ACTIVE" является опциональным. Если не планируется использовать флаг активности узла, то в классе-наследнике необходимо задать константу:
```php
const USE_GLOBAL_ACTIVE = false;
```

Пример класса можно посмотреть в файле "lib/tests/ctdata.php". Пример структуры таблицы:
```mysql
CREATE TABLE `test_ct_data` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `PARENT_ID` int(11) DEFAULT NULL,
  `NAME` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `SORT` int(11) NOT NULL DEFAULT '500',
  `ACTIVE` char(1) COLLATE utf8_unicode_ci DEFAULT 'Y',
  `GLOBAL_ACTIVE` char(1) COLLATE utf8_unicode_ci DEFAULT 'Y',
  PRIMARY KEY (`ID`),
  KEY `PARENT_ID` (`PARENT_ID`),
  KEY `SORT` (`SORT`),
  CONSTRAINT `test_ct_data_ibfk_1` FOREIGN KEY (`PARENT_ID`) REFERENCES `test_ct_data` (`ID`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
```

### Таблица для хранения связей

Используется обычный класс-наследник от Entity\DataManager.

Метод getMap() обязательно должен содержать поля:
<ul>
<li>PARENT_ID - Идентификатор предка</li>
<li>CHILD_ID - Идентификатор наследника</li>
<li>DEPTH_LEVEL - уровень вложенности</li>
<li>SORT - сортировка</li>
</ul> 

Пример класса можно посмотреть в файле "lib/tests/ctpath.php". Пример структуры таблицы:
```mysql
CREATE TABLE `test_ct_path` (
  `PARENT_ID` int(11) NOT NULL,
  `CHILD_ID` int(11) NOT NULL,
  `DEPTH_LEVEL` int(11) DEFAULT NULL,
  `SORT` int(11) DEFAULT NULL,
  PRIMARY KEY (`PARENT_ID`,`CHILD_ID`),
  KEY `SORT` (`SORT`),
  KEY `test_ct_path_ibfk_2` (`CHILD_ID`),
  CONSTRAINT `test_ct_path_ibfk_1` FOREIGN KEY (`PARENT_ID`) REFERENCES `test_ct_data` (`ID`) ON DELETE NO ACTION,
  CONSTRAINT `test_ct_path_ibfk_2` FOREIGN KEY (`CHILD_ID`) REFERENCES `test_ct_data` (`ID`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
```

для установки связи между таблицами в классе-наследнике от CTDataManager обязательно необходимо добавить метод:
```php
public static function getPathEntity()
{
   return CTPathTable::getEntity();
}

```

### Примеры
#### Добавление новой записи в корень дерева
```php
CTDataTable::add(
    array(
        'NAME' => 'ROOT ROW'
    )
);
```

#### Добавление записи в существующую ветку
```php
CTDataTable::add(
    array(
        'PARENT_ID' => $parent_node_id,
        'NAME' => 'CHILD ROW'
    )
);
```

#### Перемещение записи или целой ветки в новую ветку
```php
CTDataTable::update(
    $id,
    array(
        'PARENT_ID' => $new_parent_node_id
    )
);
```

#### Получение упорядоченного дерева, начиная от конкретного узла

Одним из недостатков "Closure Tables" является сложность получения упорядоченного дерева элементов
```php
$res = CTDataTable::getList([
   'select' => [
       'ID',
       'NAME',
       'SORT',
       'SORTCALC'
   ],
   'runtime' => [
       new Bitrix\Main\Entity\ReferenceField(
           'PATH',
           CTPathTable::getEntity(),
           ['=this.ID' => 'ref.CHILD_ID'],
           ['join_type' => 'INNER']
       ),
       new Bitrix\Main\Entity\ReferenceField(
           'SORTPATH',
           CTPathTable::getEntity(),
           ['=this.PATH.CHILD_ID' => 'ref.CHILD_ID'],
           ['join_type' => 'INNER']
       ),
       new Bitrix\Main\Entity\ExpressionField('SORTCALC',
           "GROUP_CONCAT(LPAD(%s,10,'0') ORDER BY %s DESC SEPARATOR ',')", ['SORTPATH.SORT', 'SORTPATH.DEPTH_LEVEL']
       )
   ],
   'filter' => [
       '=PATH.PARENT_ID' => $nodeId
   ],
   'group' => [
       'ID', 'NAME'
   ],
   'order' => [
       'SORTCALC'
   ]
]);
```

Поле "SORT" из таблицы данных обновляется в таблице связей при каждом изменении сортировки. При выводе упорядоченного дерева для каждой записи формируется цепочка из сортировок всех предков, дополненная слева нулями:
```
ID	    |NAME	        |SORT	|SORTCALC
--------------------------------------------------------------------
36210	|NODE #36210	|1316	|0000001316
36335	|NODE #36335	|3947	|0000001316,0000003947
36355	|NODE #36355	|1703	|0000001316,0000003947,0000001703
36354	|NODE #36354	|4817	|0000001316,0000003947,0000004817
36370	|NODE #36370	|2357	|0000001316,0000003947,0000004817,0000002357
36223	|NODE #36223	|3079	|0000001316,0000003947,0000004817,0000003079
36229	|NODE #36229	|2112	|0000001316,0000003947,0000004817,0000003079,0000002112
36296	|NODE #36296	|2044	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044
36205	|NODE #36205	|1801	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044,0000001801
36301	|NODE #36301	|936	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044,0000001801,0000000936
36346	|NODE #36346	|677	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044,0000001801,0000000936,0000000677
36249	|NODE #36249	|4021	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044,0000001801,0000000936,0000004021
36381	|NODE #36381	|2782	|0000001316,0000003947,0000004817,0000003079,0000002112,0000002044,0000002782
```  
по этой цепочке и производится сортировка

#### Получение только корневых элементов
```php
$res = CTDataTable::getList(
    array(
        'select' => array(
            'ID',
            'NAME'
        ),
        'filter' => array(
            '=PARENT_ID' => null
        ),
        'order' => array(
            'SORT' => 'ASC'
        )
    )
);
```

#### Получение всех потомков конкретной ветки дерева (включая сам узел)
```php
$res = CTDataTable::getList([
   'select' => [
       'ID',
       'NAME'
   ],
   'runtime' => [
       new Bitrix\Main\Entity\ReferenceField(
           'PATH',
           CTPathTable::getEntity(),
           ['=this.ID' => 'ref.CHILD_ID'],
           ['join_type' => 'INNER']
       )
   ],
   'filter' => [
       '=PATH.PARENT_ID' => $nodeId
   ]
]);
```

#### Получение всех предков конкретной ветки дерева (включая сам узел)
```php
$res = CTDataTable::getList([
   'select' => [
       'ID',
       'NAME'
   ],
   'runtime' => [
       new Bitrix\Main\Entity\ReferenceField(
           'PATH',
           CTPathTable::getEntity(),
           ['=this.ID' => 'ref.PARENT_ID'],
           ['join_type' => 'INNER']
       )
   ],
   'filter' => [
       '=PATH.CHILD_ID' => $nodeId
   ]
]);
```

#### Удаление узла
```php
$deleteResult = CTDataTable::delete($nodeId);
```
перед удалением проверяется наличие дочерних записей. Если они есть, то выдается ошибка

#### Удаление всей ветки
```php
$deleteResult = CTDataTable::deleteCascade($nodeId);
```
Все узлы удаляются по очереди в цикле, начиная с самых последних. Однако, поскольку "Closure Tables" поддерживают ссылочную целостность, можно произвести каскадное удаление средствами MySQL.
Для этого внешние ключи в таблицах должны быть заданы с опцией "ON DELETE CASCADE", а в классе-наследнике CTDataManager необходимо установить константу:
```php
const USE_CONSTRAINT_DELETE = false;
```
**ВНИМАНИЕ!** при таком способе удаления на всех дочерних записях не будут выполнены обработчики событий OnBeforeDelete, OnDelete и OnAfterDelete