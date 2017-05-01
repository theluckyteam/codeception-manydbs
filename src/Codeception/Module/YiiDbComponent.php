<?php
namespace Codeception\Module;

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
    ];

    /**
     * @var array Список обязательных настроек
     */
    protected $requiredFields = ['component'];

    /**
     * @inheritdoc
     */
    private $_isPopulated = false;

    /**
     * @inheritdoc
     */
    private $_isBootstrapped = false;

    /**
     * @inheritdoc
     */
    public function _before(TestInterface $test)
    {
        if (!$this->getIsBootstrapped()) {
            $this->bootstrap();
        } else {
            if ($this->getReconnect()) {
                $this->connect();
            }
            if ($this->getCleanup()
                    && !$this->getIsPopulated()) {
                $this->clean();
                $this->loadDump();
            }
        }
    }

    /**
     * @inheritdoc
     */
    private function bootstrap()
    {
        $this->connect();
        if ($this->getPopulate()) {
            if ($this->getCleanup()) {
                $this->clean();
            }
            $this->loadDump();
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
     * @return void
     */
    private function loadDump()
    {
        $files = (array) $this->getDump();
        foreach ($files as $file) {
            $this->loadSqlDumpfile($file);
        }
    }
    
    /**
     * Загрузить файл дампа базы данных
     *
     * @param string $fileName Имя файла с дампом базы данных
     * @param string $delimiter Разделитель SQL выражений
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
     * @inheritdoc
     */
    public function _after(TestInterface $test)
    {
        $this->setIsPopulated(false);
        if ($this->getReconnect()) {
            $this->disconnect();
        }
    }

    /**
     * @inheritdoc
     */
    public function haveInDatabase($table, $columns)
    {
        $connection = $this->getConnection();
        $command = $connection->createCommand()
            ->insert($table, $columns);
        $command->execute();
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
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
     * @inheritdoc
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
