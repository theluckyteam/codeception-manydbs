<?php
namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Module;
use Codeception\TestInterface;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Query;
use yii\di\ServiceLocator;

/**
 * Компонент для работы с базой данных
 */
class YiiDbComponent extends Module
{
    /**
     * @var array Конфигурационный массив с настройками
     */
    protected $config = [
        'dump' => [],
        'cleanup' => true,
        'populate' => true,
        'reconnect' => false,
        'component' => null,
        'testCaseDumpMethod' => null,
    ];

    /**
     * @var array Список обязательных настроек
     */
    protected $requiredFields = ['component'];

    /**
     * @var boolean Флаг говорящий о том инициализирован ли компонет
     */
    private $_isBootstrapped = false;

    /**
     * @var boolean Флаг говорящий о том были ли презагружены данные
     */
    private $_isPopulated = false;

    /**
     * Codeception hook, выполняется каждый раз перед TestCase
     *
     * @param TestInterface $test Экземпляр TestCase
     *
     * @return void
     */
    public function _before(TestInterface $test)
    {
        if (!$this->getIsBootstrapped()) {
            $this->bootstrap($test);
        } else {
            if ($this->getReconnect()) {
                $this->connect();
            }
            if ($this->getCleanup()
                    && !$this->getIsPopulated()) {
                $this->clean();
                $this->loadDump($test);
            }
        }
    }

    /**
     * Инициализировать компонент, презагрузить данные
     *
     * @param TestInterface $test Экземпляр TestCase
     *
     * @return void
     */
    private function bootstrap(TestInterface $test = null)
    {
        $this->connect();
        if ($this->getPopulate()) {
            if ($this->getCleanup()) {
                $this->clean();
            }
            $this->loadDump($test);
            $this->setIsPopulated();
        }
        $this->setIsBootstrapped();
    }

    /**
     * Соединиться с базой данных
     *
     * @return void
     */
    private function connect()
    {
        $connection = $this->getConnection();
        $connection->open();
    }

    /**
     * Закрыть соединение с базой данных
     *
     * @return void
     */
    private function disconnect()
    {
        /** @var Connection $connection */
        $connection = $this->getConnection();
        $connection->close();
    }

    /**
     * Очистить базу данных
     *
     * @return void
     */
    private function clean()
    {
        /** @var Connection $connection */
        $connection = $this->getConnection();
        $schema = $connection->getSchema();

        $tableSchemas = $schema->getTableSchemas();
        foreach ($tableSchemas as $tableSchema) {
            $command = $connection->createCommand()
                ->dropTable($tableSchema->name);
            $command->execute();
        }
    }

    /**
     * Загрузить дамп базы данных
     *
     * @param TestInterface $test Экземпляр TestCase
     *
     * @return void
     */
    private function loadDump(TestInterface $test = null)
    {
        if ($test && $this->testCaseDumpMethodExists($test)) {
            $files = (array) call_user_func([$test, $this->getTestCaseDumpMethod()]);
        } else {
            $files = (array) $this->getDump();
        }
        foreach ($files as $file) {
            if ($fileName = trim($file)) {
                $fileName = $this->prepareDumpFilename($fileName);
                $this->loadSqlDumpfile($fileName);
            }
        }
    }

    /**
     * Подготовить полное имя dump-файла
     *
     * @param string $fileName имя файла
     *
     * @return string полное имя файла
     */
    private function prepareDumpFilename($fileName)
    {
        if ($fileName[0] !== '/') {
            $fileName = Configuration::projectDir() . $fileName;
        }
        return $fileName;
    }

    /**
     * Загрузить файл дампа базы данных
     *
     * @param string $fileName Имя файла с дампом базы данных
     * @param string $delimiter Разделитель SQL выражений
     *
     * @return void
     */
    private function loadSqlDumpfile($fileName, $delimiter = ';')
    {
        /** @var Connection $connection */
        $connection = $this->getConnection();
        $delimiterLength = strlen($delimiter);
        if ($dump = file_get_contents($fileName)) {
            if ($connection->driverName === 'mysql') {
                // remove C-style comments (except MySQL directives)
                $dump = preg_replace('%/\*(?!!\d+).*?\*/%s', '', $dump);
            }
            if (!empty($dump)) {
                $dump = preg_split('/\r\n|\n|\r/', $dump, -1, PREG_SPLIT_NO_EMPTY);
                $statement = '';
                foreach ($dump as $line) {
                    $line = trim($line);
                    if ($line === ''
                        || $line === $delimiter
                        || preg_match('~^((--.*?)|(#))~s', $line))
                    {
                        continue;
                    }
                    $statement .= "\n" . rtrim($line);

                    if (substr($statement, -1 * $delimiterLength, $delimiterLength) == $delimiter) {
                        $command = $connection->createCommand($statement);
                        $command->execute();
                        $statement = '';
                    }
                }
            }
        }
    }

    /**
     * Codeception hook, выполняется каждый раз после TestCase
     *
     * @param TestInterface $test Экземпляр TestCase
     *
     * @return void
     */
    public function _after(TestInterface $test)
    {
        $this->setIsPopulated(false);
        if ($this->getReconnect()) {
            $this->disconnect();
        }
    }

    /**
     * В базе данных есть следующие значения
     *
     * @param string $table имя таблицы
     * @param array $columns ассоциативный массив, определяющий запись БД
     *
     * @return void
     * @throws ModuleConfigException
     * @throws \yii\db\Exception
     */
    public function haveInDatabase($table, $columns)
    {
        $connection = $this->getConnection();
        $command = $connection->createCommand()
            ->insert($table, $columns);
        $command->execute();
    }

    /**
     * Количество записей в базе данных попадающих под условие
     *
     * @param string $table имя таблица
     * @param array $condition условие поиска записей
     *
     * @return integer
     * @throws ModuleConfigException
     * @throws \yii\db\Exception
     */
    public function countInDatabase($table, $condition)
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->from($table)
            ->where($condition);
        return $query->count('*', $connection);
    }

    /**
     * Проверяет наличие записей в базе данных попадающих под условие
     *
     * @param string $table имя таблица
     * @param array $condition условие поиска записей
     *
     * @throws ModuleConfigException
     * @throws \yii\db\Exception
     */
    public function seeInDatabase($table, $condition)
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->from($table)
            ->where($condition)
            ->limit(1);
        $exists = $query->exists($connection);

        \PHPUnit_Framework_Assert::assertTrue(
            $exists, 'There are no entries in the database that fall under the condition.'
        );
    }

    /**
     * Проверяет отсутствие записей в базе данных попадающих под условие
     *
     * @param string $table имя таблица
     * @param array $condition условие поиска записей
     *
     * @throws ModuleConfigException
     * @throws \yii\db\Exception
     */
    public function dontSeeInDatabase($table, $condition)
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->from($table)
            ->where($condition)
            ->limit(1);
        $exists = $query->exists($connection);

        \PHPUnit_Framework_Assert::assertFalse(
            $exists, 'There are records in the database that fall under the condition.'
        );
    }

    /**
     * Получить флаг говорящий о том является ли компонет инициализованным
     *
     * @return boolean
     */
    private function getIsBootstrapped()
    {
        return $this->_isBootstrapped;
    }

    /**
     * Установить флаг говорящий о том является ли компонет инициализованным
     *
     * @param $isBootstrapped boolean
     */
    private function setIsBootstrapped($isBootstrapped = true)
    {
        $this->_isBootstrapped = $isBootstrapped;
    }

    /**
     * Получить флаг говорящий о том были ли загружены данные
     *
     * @return boolean
     */
    private function getIsPopulated()
    {
        return $this->_isPopulated;
    }

    /**
     * Установить флаг говорящий о том были ли загружены данные
     *
     * @param $isPopulated boolean
     */
    private function setIsPopulated($isPopulated = true)
    {
        $this->_isPopulated = $isPopulated;
    }

    /**
     * Получить метод возвращающий список файлов для загрузки данных в базу данных
     *
     * @param TestInterface $test Экземпляр TestCase
     *
     * @return array
     */
    private function testCaseDumpMethodExists(TestInterface $test)
    {
        if (!(array_key_exists('testCaseDumpMethod', $this->config) && $this->config['testCaseDumpMethod'])) {
            return false;
        }
        return method_exists($test, $this->config['testCaseDumpMethod']);
    }

    /**
     * Получить метод возвращающий список файлов для загрузки данных в базу данных
     *
     * @return array
     */
    private function getTestCaseDumpMethod()
    {
        return $this->config['testCaseDumpMethod'];
    }

    /**
     * Список файлов для загрузки данных в базу данных
     *
     * @return array
     */
    private function getDump()
    {
        return $this->config['dump'];
    }

    /**
     * Получить флаг говорящий о необходимости после каждого TestCase очищать базу данных
     *
     * @return boolean
     */
    private function getCleanup()
    {
        return $this->config['cleanup'];
    }

    /**
     * Получить флаг говорящий о необходимости после каждого TestCase подгрущать данные в базу данных
     *
     * @return boolean
     */
    private function getPopulate()
    {
        return $this->config['populate'];
    }

    /**
     * Получить флаг говорящий о необходимости после каждого TestCase переоткрывать соединение с базой данных
     *
     * @return boolean
     */
    private function getReconnect()
    {
        return $this->config['reconnect'];
    }

    /**
     * Получить имя компонента для работы с базой данных
     *
     * @return string
     */
    public function getComponent()
    {
        return $this->config['component'];
    }

    /**
     * Получить соединение с базой данных
     *
     * @return Connection
     *
     * @throws ModuleConfigException
     */
    private function getConnection()
    {
        $component = $this->getComponent();
        try {
            /** @var ServiceLocator $serviceLocator */
            $serviceLocator = $this->getApplicationServiceLocator();

            return $serviceLocator->get($component);
        } catch (InvalidConfigException $exception) {
            throw new ModuleConfigException($this, 'Service locator has not "' . $component . '" component.');
        }
    }

    /**
     * Получить ServiceLocator приложения
     *
     * @return ServiceLocator
     *
     * @throws ModuleConfigException
     */
    private function getApplicationServiceLocator()
    {
        $module = $this->getYiiModule();
        if ($application = $module->app) {
            return $application;
        }

        throw new ModuleConfigException($this, 'Module "Yii2" has not Service locator.');
    }

    /**
     * Получить Yii-модуль Codeception
     *
     * @return Yii2
     *
     * @throws ModuleConfigException
     */
    private function getYiiModule()
    {
        if ($module = $this->getModule('Yii2')) {
            return $module;
        }

        throw new ModuleConfigException($this, 'Module "Yii2" was not configured.');
    }
}
