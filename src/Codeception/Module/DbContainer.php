<?php
namespace Codeception\Module;

use Codeception\Exception\ConfigurationException;
use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Codeception\Step;
use Codeception\TestInterface;

/**
 * Контейнер для работы с несколькими базами данных
 */
class DbContainer extends Module
{
    /**
     * @var array Конфигурационный массив с настройками
     */
    protected $config = [
        'databases' => [],
    ];

    /**
     * @var array Список обязательных настроек
     */
    protected $requiredFields = [
        'databases'
    ];

    /**
     * @var array Список баз данных
     */
    private $_databases = [];

    /**
     * @inheritdoc
     */
    public function __construct(ModuleContainer $moduleContainer, $config)
    {
        parent::__construct($moduleContainer, $config);
        $this->_databases = $this->prepareDatabases(
            $this->getDatabasesConfig()
        );
    }

    /**
     * @inheritdoc
     */
    public function _initialize()
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_initialize();
        }
    }

    /**
     * @inheritdoc
     */
    public function _cleanup()
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_cleanup();
        }
    }

    /**
     * @inheritdoc
     */
    public function _beforeSuite($settings = [])
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_beforeSuite($settings);
        }
    }

    /**
     * @inheritdoc
     */
    public function _afterSuite()
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_afterSuite();
        }
    }

    /**
     * @inheritdoc
     */
    public function _before(TestInterface $test)
    {
        foreach ($this->getDatabases() as $name => $adapter) {
            $adapter->_before($test);
        }
    }

    /**
     * @inheritdoc
     */
    public function _after(TestInterface $test)
    {
        foreach ($this->getDatabases() as $name => $adapter) {
            $adapter->_after($test);
        }
    }

    /**
     * @inheritdoc
     */
    public function _beforeStep(Step $step)
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_beforeStep($step);
        }
    }

    /**
     * @inheritdoc
     */
    public function _afterStep(Step $step)
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_afterStep($step);
        }
    }

    /**
     * @inheritdoc
     */
    public function _failed(TestInterface $test, $fail)
    {
        foreach ($this->getDatabases() as $name => $database) {
            $database->_failed($test, $fail);
        }
    }

    /**
     * Подготовить экземпляры баз данных из настроек
     *
     * @param array $databasesOptions настройки баз данных
     *
     * @return array
     * @throws ConfigurationException
     * @throws ModuleConfigException
     */
    private function prepareDatabases($databasesOptions)
    {
        $databases = [];
        foreach ($databasesOptions as $databaseOptions) {
            $databaseClass = $this->prepareClassName(
                key($databaseOptions)
            );
            $databaseConfig = current($databaseOptions);

            $database = $this->createDatabase($databaseClass, $databaseConfig);
            $databaseName = $this->prepareDatabaseName($database);

            $databases[$databaseName] = $database;
        }

        return $databases;
    }

    /**
     * Подготовить имя класса базы данных
     *
     * @param string $class имя класса
     *
     * @return string имя класса
     * @throws ConfigurationException
     */
    private function prepareClassName($class)
    {
        $hasNamespace = (strpos($class, '\\') !== false);
        if ($hasNamespace) {
            return $class;
        }

        // standard module
        $moduleClass = ModuleContainer::MODULE_NAMESPACE . $class;
        if (class_exists($moduleClass)) {
            return $moduleClass;
        }

        throw new ConfigurationException('Database "' . $class . '" could not be found and loaded.');
    }

    /**
     * Подготовить имя базы данных
     *
     * @param $database
     *
     * @return string имя базы данных
     * @throws ModuleConfigException
     */
    private function prepareDatabaseName($database)
    {
        $class = get_class($database);
        switch ($class) {
            case 'Codeception\Module\YiiDbComponent':
                /** @var \Codeception\Module\YiiDbComponent $database */
                return $database->getComponent();
                break;
        }

        throw new ModuleConfigException($this, 'Can not prepare database name.');
    }

    /**
     * Создать объект базы данных
     *
     * @param string $class имя базы данных
     * @param array $config настройки
     *
     * @return mixed
     */
    private function createDatabase($class, $config)
    {
        $database = new $class($this->moduleContainer, $config);

        return $database;
    }

    /**
     * Использовать базу данных
     *
     * @param string $name имя базы данных
     *
     * @return mixed
     * @throws ModuleConfigException
     */
    public function useDatabase($name)
    {
        if (array_key_exists($name, $this->_databases)) {
            return $this->_databases[$name];
        }
        throw new ModuleConfigException($this, '');
    }

    /**
     * Получить экземпляры баз данных
     *
     * @return array
     */
    public function getDatabases()
    {
        return $this->_databases;
    }

    /**
     * Получить конфигурацию нескольких баз данных
     *
     * @return array
     */
    private function getDatabasesConfig()
    {
        return $this->config['databases'];
    }
}
