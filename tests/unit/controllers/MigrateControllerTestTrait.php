<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\console\tests\unit\controllers;

use yii\base\Module;
use yii\console\controllers\BaseMigrateController;
use yii\di\AbstractContainer;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;
use yii\tests\TestCase;

/**
 * This trait provides unit tests shared by the different migration controllers implementations.
 * @see BaseMigrateController
 */
trait MigrateControllerTestTrait
{
    /* @var $this TestCase */

    /**
     * @var string name of the migration controller class, which is under test.
     */
    protected $migrateControllerClass;
    /**
     * @var string name of the migration base class.
     */
    protected $migrationBaseClass;
    /**
     * @var string test migration path.
     */
    protected $migrationPath;
    /**
     * @var string test migration namespace
     */
    protected $migrationNamespace;


    public function setUpMigrationPath()
    {
        $this->migrationNamespace = 'yii\tests\runtime\test_migrations';
        $this->migrationPath = $this->app->getAlias('@yii/tests/runtime/test_migrations');
        FileHelper::createDirectory($this->migrationPath);
        if (!file_exists($this->migrationPath)) {
            $this->markTestIncomplete('Unit tests runtime directory should have writable permissions!');
        }
    }

    public function tearDownMigrationPath()
    {
        FileHelper::removeDirectory($this->migrationPath);
        FileHelper::removeDirectory($this->app->getAlias('@yii/tests/runtime/app_migrations'));
        FileHelper::removeDirectory($this->app->getAlias('@yii/tests/runtime/extension_migrations'));
    }

    /**
     * @return array applied migration entries
     */
    abstract protected function getMigrationHistory();

    /**
     * Creates test migrate controller instance.
     * @param array $config controller configuration.
     * @return BaseMigrateController migrate command instance.
     */
    protected function createMigrateController(array $config = [])
    {
        $module = $this->getMockBuilder(Module::class)
            ->setConstructorArgs(['console', $this->app])
            ->setMethods(['fake'])
            ->getMock();
        $class = $this->migrateControllerClass;
        $migrateController = new $class('migrate', $module);
        $migrateController->interactive = false;
        $migrateController->migrationPath = $this->migrationPath;
        return AbstractContainer::configure($migrateController, $config);
    }

    /**
     * Emulates running of the migrate controller action.
     * @param string $actionID id of action to be run.
     * @param array $args action arguments.
     * @param array $config controller configuration.
     * @return string command output.
     */
    protected function runMigrateControllerAction($actionID, array $args = [], array $config = [])
    {
        $controller = $this->createMigrateController($config);
        ob_start();
        ob_implicit_flush(false);
        $controller->run($actionID, $args);

        return ob_get_clean();
    }

    /**
     * @param string $name
     * @param string|null $date
     * @param string|null $path
     * @return string generated class name
     */
    protected function createMigration($name, $date = null, $path = null)
    {
        if ($date === null) {
            $date = gmdate('ymd_His');
        }
        $class = 'm' . $date . '_' . $name;
        $baseClass = $this->migrationBaseClass;

        $code = <<<CODE
<?php

class {$class} extends {$baseClass}
{
    public function up()
    {
    }

    public function down()
    {
    }
}
CODE;
        file_put_contents(($path ? $this->app->getAlias($path) : $this->migrationPath) . DIRECTORY_SEPARATOR . $class . '.php', $code);
        return $class;
    }

    /**
     * @param string $name
     * @param string|null $date
     * @return string generated class name
     */
    protected function createNamespaceMigration($name, $date = null)
    {
        if ($date === null) {
            $date = gmdate('ymdHis');
        }
        $class = 'M' . $date . ucfirst($name);
        $baseClass = $this->migrationBaseClass;
        $namespace = $this->migrationNamespace;

        $code = <<<CODE
<?php

namespace {$namespace};

class {$class} extends \\{$baseClass}
{
    public function up()
    {
    }

    public function down()
    {
    }
}
CODE;
        file_put_contents($this->migrationPath . DIRECTORY_SEPARATOR . $class . '.php', $code);
        return $class;
    }

    /**
     * Change class name migration to $class.
     * @param string $class name class
     * @return string content generated class migration
     * @see https://github.com/yiisoft/yii2/pull/10213
     */
    protected function parseNameClassMigration($class)
    {
        $files = FileHelper::findFiles($this->migrationPath);
        $file = file_get_contents($files[0]);
        if (preg_match('/class (m\d+_\d+_.*) extends Migration/', $file, $match)) {
            $file = str_replace($match[1], $class, $file);
        }
        $this->tearDownMigrationPath();
        return $file;
    }

    /**
     * Checks if applied migration history matches expected one.
     * @param array $expectedMigrations migration names in expected order
     * @param string $message failure message
     */
    protected function assertMigrationHistory(array $expectedMigrations, $message = '')
    {
        $success = true;
        $migrationHistory = $this->getMigrationHistory();
        $appliedMigrations = $migrationHistory;
        foreach ($expectedMigrations as $expectedMigrationName) {
            $appliedMigration = array_shift($appliedMigrations);
            if (!StringHelper::matchWildcard(strtr($expectedMigrationName, ['\\' => DIRECTORY_SEPARATOR]), strtr($appliedMigration['version'], ['\\' => DIRECTORY_SEPARATOR]))) {
                $success = false;
                break;
            }
        }
        if (!$success) {
            $message .= "\n";
            $message .= 'Expected: ' . var_export($expectedMigrations, true) . "\n";

            $actualMigrations = [];
            foreach ($migrationHistory as $row) {
                $actualMigrations[] = $row['version'];
            }
            $message .= 'Actual: ' . var_export($actualMigrations, true) . "\n";
        }
        $this->assertTrue($success, $message);
    }

    // Tests :

    public function testCreate()
    {
        $migrationName = 'test_migration';
        $this->runMigrateControllerAction('create', [$migrationName]);
        $files = FileHelper::findFiles($this->migrationPath);
        $this->assertCount(1, $files, 'Unable to create new migration!');
        $this->assertContains($migrationName, basename($files[0]), 'Wrong migration name!');
    }

    public function testUp()
    {
        $this->createMigration('test_up1');
        $this->createMigration('test_up2');

        $this->runMigrateControllerAction('up');

        $this->assertMigrationHistory(['m*_base', 'm*_test_up1', 'm*_test_up2']);
    }

    /**
     * @depends testUp
     */
    public function testUpCount()
    {
        $this->createMigration('test_down1');
        $this->createMigration('test_down2');

        $this->runMigrateControllerAction('up', [1]);

        $this->assertMigrationHistory(['m*_base', 'm*_test_down1']);
    }

    /**
     * @depends testUp
     */
    public function testDownCount()
    {
        $this->createMigration('test_down_count1');
        $this->createMigration('test_down_count2');

        $this->runMigrateControllerAction('up');
        $this->runMigrateControllerAction('down', [1]);

        $this->assertMigrationHistory(['m*_base', 'm*_test_down_count1']);
    }

    /**
     * @depends testDownCount
     */
    public function testDownAll()
    {
        $this->createMigration('test_down_all1');
        $this->createMigration('test_down_all2');

        $this->runMigrateControllerAction('up');
        $this->runMigrateControllerAction('down', ['all']);

        $this->assertMigrationHistory(['m*_base']);
    }

    /**
     * @depends testUp
     */
    public function testHistory()
    {
        $output = $this->runMigrateControllerAction('history');
        $this->assertContains('No migration', $output);

        $this->createMigration('test_history1');
        $this->createMigration('test_history2');
        $this->runMigrateControllerAction('up');

        $output = $this->runMigrateControllerAction('history');
        $this->assertContains('_test_history1', $output);
        $this->assertContains('_test_history2', $output);
    }

    /**
     * @depends testUp
     */
    public function testNew()
    {
        $this->createMigration('test_new1');

        $output = $this->runMigrateControllerAction('new');
        $this->assertContains('_test_new1', $output);

        $this->runMigrateControllerAction('up');

        $output = $this->runMigrateControllerAction('new');
        $this->assertNotContains('_test_new1', $output);
    }

    public function testMark()
    {
        $version = '010101_000001';
        $this->createMigration('test_mark1', $version);

        $this->runMigrateControllerAction('mark', [$version]);

        $this->assertMigrationHistory(['m*_base', 'm*_test_mark1']);
    }

    public function testMarkBase()
    {
        $version = '010101_000001';
        $this->createMigration('test_mark1', $version);

        $this->runMigrateControllerAction('up');
        $this->assertMigrationHistory(['m*_base', 'm*_test_mark1']);

        $this->runMigrateControllerAction('mark', [BaseMigrateController::BASE_MIGRATION]);
        $this->assertMigrationHistory(['m*_base']);
    }

    public function testTo()
    {
        $version = '020202_000001';
        $this->createMigration('to1', $version);

        $this->runMigrateControllerAction('to', [$version]);

        $this->assertMigrationHistory(['m*_base', 'm*_to1']);
    }

    /**
     * @depends testUp
     */
    public function testRedo()
    {
        $this->createMigration('test_redo1');
        $this->runMigrateControllerAction('up');

        $this->runMigrateControllerAction('redo');

        $this->assertMigrationHistory(['m*_base', 'm*_test_redo1']);
    }

    // namespace :

    /**
     * @depends testCreate
     */
    public function testNamespaceCreate()
    {
        // default namespace apply :
        $migrationName = 'testDefaultNamespace';
        $this->runMigrateControllerAction('create', [$migrationName], [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ]);
        $files = FileHelper::findFiles($this->migrationPath);
        $fileContent = file_get_contents($files[0]);
        $this->assertContains("namespace {$this->migrationNamespace};", $fileContent);
        $this->assertRegExp('/class M[0-9]{12}' . ucfirst($migrationName) . '/s', $fileContent);
        unlink($files[0]);

        // namespace specify :
        $migrationName = 'test_namespace_specify';
        $this->runMigrateControllerAction('create', [$this->migrationNamespace . '\\' . $migrationName], [
            'migrationPath' => $this->migrationPath,
            'migrationNamespaces' => [$this->migrationNamespace],
        ]);
        $files = FileHelper::findFiles($this->migrationPath);
        $fileContent = file_get_contents($files[0]);
        $this->assertContains("namespace {$this->migrationNamespace};", $fileContent);
        unlink($files[0]);

        // no namespace:
        $migrationName = 'test_no_namespace';
        $this->runMigrateControllerAction('create', [$migrationName], [
            'migrationPath' => $this->migrationPath,
            'migrationNamespaces' => [$this->migrationNamespace],
        ]);
        $files = FileHelper::findFiles($this->migrationPath);
        $fileContent = file_get_contents($files[0]);
        $this->assertNotContains("namespace {$this->migrationNamespace};", $fileContent);
    }

    /**
     * @depends testUp
     */
    public function testNamespaceUp()
    {
        $this->createNamespaceMigration('nsTest1');
        $this->createNamespaceMigration('nsTest2');

        $this->runMigrateControllerAction('up', [], [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ]);

        $this->assertMigrationHistory([
            'm*_*_base',
            $this->migrationNamespace . '\\M*NsTest1',
            $this->migrationNamespace . '\\M*NsTest2',
        ]);
    }

    /**
     * @depends testNamespaceUp
     * @depends testDownCount
     */
    public function testNamespaceDownCount()
    {
        $this->createNamespaceMigration('down1');
        $this->createNamespaceMigration('down2');

        $controllerConfig = [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ];
        $this->runMigrateControllerAction('up', [], $controllerConfig);
        $this->runMigrateControllerAction('down', [1], $controllerConfig);

        $this->assertMigrationHistory([
            'm*_*_base',
            $this->migrationNamespace . '\\M*Down1',
        ]);
    }

    /**
     * @depends testNamespaceUp
     * @depends testHistory
     */
    public function testNamespaceHistory()
    {
        $controllerConfig = [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ];

        $output = $this->runMigrateControllerAction('history', [], $controllerConfig);
        $this->assertContains('No migration', $output);

        $this->createNamespaceMigration('history1');
        $this->createNamespaceMigration('history2');
        $this->runMigrateControllerAction('up', [], $controllerConfig);

        $output = $this->runMigrateControllerAction('history', [], $controllerConfig);
        $this->assertRegExp('/' . preg_quote($this->migrationNamespace) . '.*History1/s', $output);
        $this->assertRegExp('/' . preg_quote($this->migrationNamespace) . '.*History2/s', $output);
    }

    /**
     * @depends testMark
     */
    public function testNamespaceMark()
    {
        $controllerConfig = [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ];

        $version = '010101000001';
        $this->createNamespaceMigration('mark1', $version);

        $this->runMigrateControllerAction('mark', [$this->migrationNamespace . '\\M' . $version], $controllerConfig);

        $this->assertMigrationHistory(['m*_base', $this->migrationNamespace . '\\M*Mark1']);
    }

    /**
     * @depends testTo
     */
    public function testNamespaceTo()
    {
        $controllerConfig = [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ];

        $version = '020202000020';
        $this->createNamespaceMigration('to1', $version);

        $this->runMigrateControllerAction('to', [$this->migrationNamespace . '\\M' . $version], $controllerConfig);

        $this->assertMigrationHistory(['m*_base', $this->migrationNamespace . '\\M*To1']);
    }

    /**
     * Test migration with using multiple migration paths and namespaces.
     */
    public function testCombinedMigrationProcess()
    {
        FileHelper::createDirectory($this->app->getAlias('@yii/tests/runtime/app_migrations'));
        FileHelper::createDirectory($this->app->getAlias('@yii/tests/runtime/extension_migrations'));
        $controllerConfig = [
            'migrationPath' => [$appPath = '@yii/tests/runtime/app_migrations', $extensionPath = '@yii/tests/runtime/extension_migrations'],
            'migrationNamespaces' => [$this->migrationNamespace],
        ];

        $this->createMigration('app_migration1', '010101_000001', $appPath);
        $this->createMigration('ext_migration1', '010101_000002', $extensionPath);
        $this->createMigration('app_migration2', '010101_000003', $appPath);
        $this->createNamespaceMigration('NsMigration', '010101000004');

        // yii migrate/up 1
        $this->runMigrateControllerAction('up', [1], $controllerConfig);
        $this->assertMigrationHistory(['m*_base', 'm010101_000001_app_migration1']);

        // yii migrate/up
        $this->runMigrateControllerAction('up', [], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
            $this->migrationNamespace . '\\M010101000004NsMigration',
        ]);

        // yii migrate/to m010101_000002_ext_migration1
        $this->runMigrateControllerAction('to', ['m010101_000002_ext_migration1'], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
        ]);

        // yii migrate/mark M010101000004NsMigration
        $this->runMigrateControllerAction('mark', ['m010101_000003_app_migration2'], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
        ]);

        // yii migrate/up
        $this->runMigrateControllerAction('up', [], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
            $this->migrationNamespace . '\\M010101000004NsMigration',
        ]);

        // yii migrate/redo 2
        $this->runMigrateControllerAction('redo', [2], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
            $this->migrationNamespace . '\\M010101000004NsMigration',
        ]);

        // yii migrate/down
        $this->runMigrateControllerAction('down', [], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
        ]);

        // yii migrate/redo
        $this->runMigrateControllerAction('redo', [], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
        ]);

        // yii migrate/down 2
        $this->runMigrateControllerAction('down', [2], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
        ]);

        // yii migrate/create app_migration3
        $this->runMigrateControllerAction('create', ['app_migration3'], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
        ]);

        // yii migrate/up
        $this->runMigrateControllerAction('up', [], $controllerConfig);
        $this->assertMigrationHistory([
            'm*_base',
            'm010101_000001_app_migration1',
            'm010101_000002_ext_migration1',
            'm010101_000003_app_migration2',
            $this->migrationNamespace . '\\M010101000004NsMigration',
            'm*_app_migration3',
        ]);
        $this->assertCount(1, FileHelper::findFiles($this->app->getAlias($appPath), ['only' => ['m*_app_migration3.php']]));
    }
}
