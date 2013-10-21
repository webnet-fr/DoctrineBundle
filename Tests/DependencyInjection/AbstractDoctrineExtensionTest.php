<?php

/*
 * This file is part of the Doctrine Bundle
 *
 * The code was originally distributed inside the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 * (c) Doctrine Project, Benjamin Eberlei <kontakt@beberlei.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\EntityListenerPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\ORM\Version;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Compiler\ResolveDefinitionTemplatesPass;

abstract class AbstractDoctrineExtensionTest extends \PHPUnit_Framework_TestCase
{
    abstract protected function loadFromFile(ContainerBuilder $container, $file);

    public function testDbalLoadFromXmlMultipleConnections()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_service_multiple_connections');

        $this->compileContainer($container);

        // doctrine.dbal.mysql_connection
        $config = $container->getDefinition('doctrine.dbal.mysql_connection')->getArgument(0);

        $this->assertEquals('mysql_s3cr3t', $config['password']);
        $this->assertEquals('mysql_user', $config['user']);
        $this->assertEquals('mysql_db', $config['dbname']);
        $this->assertEquals('/path/to/mysqld.sock', $config['unix_socket']);

        // doctrine.dbal.sqlite_connection
        $config = $container->getDefinition('doctrine.dbal.sqlite_connection')->getArgument(0);
        $this->assertArrayHasKey('memory', $config);

        // doctrine.dbal.oci8_connection
        $config = $container->getDefinition('doctrine.dbal.oci_connection')->getArgument(0);
        $this->assertArrayHasKey('charset', $config);
    }

    public function testDbalLoadFromXmlSingleConnections()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_service_single_connection');

        $this->compileContainer($container);

        // doctrine.dbal.mysql_connection
        $config = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('mysql_s3cr3t', $config['password']);
        $this->assertEquals('mysql_user', $config['user']);
        $this->assertEquals('mysql_db', $config['dbname']);
        $this->assertEquals('/path/to/mysqld.sock', $config['unix_socket']);
    }

    public function testDbalLoadSingleMasterSlaveConnection()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_service_single_master_slave_connection');

        $this->compileContainer($container);

        // doctrine.dbal.mysql_connection
        $param = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('Doctrine\\DBAL\\Connections\\MasterSlaveConnection', $param['wrapperClass']);
        $this->assertTrue($param['keepSlave']);
        $this->assertEquals(
            array('user' => 'mysql_user', 'password' => 'mysql_s3cr3t', 'port' => null, 'dbname' => 'mysql_db', 'host' => 'localhost', 'unix_socket' => '/path/to/mysqld.sock'),
            $param['master']
        );
        $this->assertEquals(
            array(
                'user' => 'slave_user', 'password' => 'slave_s3cr3t', 'port' => null, 'dbname' => 'slave_db',
                'host' => 'localhost', 'unix_socket' => '/path/to/mysqld_slave.sock'
            ),
            $param['slaves']['slave1']
        );
    }

    public function testDbalLoadPoolShardingConnection()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_service_pool_sharding_connection');

        $this->compileContainer($container);

        // doctrine.dbal.mysql_connection
        $param = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('Doctrine\\DBAL\\Sharding\\PoolingShardConnection', $param['wrapperClass']);
        $this->assertEquals(new Reference('foo.shard_choser'), $param['shardChoser']);
        $this->assertEquals(
            array('user' => 'mysql_user', 'password' => 'mysql_s3cr3t', 'port' => null, 'dbname' => 'mysql_db', 'host' => 'localhost', 'unix_socket' => '/path/to/mysqld.sock'),
            $param['global']
        );
        $this->assertEquals(
            array(
                'user' => 'shard_user', 'password' => 'shard_s3cr3t', 'port' => null, 'dbname' => 'shard_db',
                'host' => 'localhost', 'unix_socket' => '/path/to/mysqld_shard.sock', 'id' => 1,
            ),
            $param['shards'][0]
        );
    }

    public function testLoadSimpleSingleConnection()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_service_simple_single_entity_manager');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.default_connection');

        $this->assertDICConstructorArguments($definition, array(
            array(
                'dbname' => 'db',
                'host' => 'localhost',
                'port' => null,
                'user' => 'root',
                'password' => null,
                'driver' => 'pdo_mysql',
                'driverOptions' => array(),
            ),
            new Reference('doctrine.dbal.default_connection.configuration'),
            new Reference('doctrine.dbal.default_connection.event_manager'),
            array(),
        ));

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getFactoryClass());
        $this->assertEquals('create', $definition->getFactoryMethod());

        $this->assertDICConstructorArguments($definition, array(
            new Reference('doctrine.dbal.default_connection'), new Reference('doctrine.orm.default_configuration')
        ));
    }

    public function testLoadSingleConnection()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_service_single_entity_manager');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.default_connection');

        $this->assertDICConstructorArguments($definition, array(
            array(
                'host' => 'localhost',
                'driver' => 'pdo_sqlite',
                'driverOptions' => array(),
                'user' => 'sqlite_user',
                'port' => null,
                'password' => 'sqlite_s3cr3t',
                'dbname' => 'sqlite_db',
                'memory' => true,
            ),
            new Reference('doctrine.dbal.default_connection.configuration'),
            new Reference('doctrine.dbal.default_connection.event_manager'),
            array(),
        ));

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getFactoryClass());
        $this->assertEquals('create', $definition->getFactoryMethod());

        $this->assertDICConstructorArguments($definition, array(
            new Reference('doctrine.dbal.default_connection'), new Reference('doctrine.orm.default_configuration')
        ));

        $configDef = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($configDef, 'setDefaultRepositoryClassName', array('Acme\Doctrine\Repository'));
    }

    public function testLoadMultipleConnections()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_service_multiple_entity_managers');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.conn1_connection');

        $args = $definition->getArguments();
        $this->assertEquals('pdo_sqlite', $args[0]['driver']);
        $this->assertEquals('localhost', $args[0]['host']);
        $this->assertEquals('sqlite_user', $args[0]['user']);
        $this->assertEquals('doctrine.dbal.conn1_connection.configuration', (string) $args[1]);
        $this->assertEquals('doctrine.dbal.conn1_connection.event_manager', (string) $args[2]);

        $this->assertEquals('doctrine.orm.em2_entity_manager', (string) $container->getAlias('doctrine.orm.entity_manager'));

        $definition = $container->getDefinition('doctrine.orm.em1_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getFactoryClass());
        $this->assertEquals('create', $definition->getFactoryMethod());

        $arguments = $definition->getArguments();
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[0]);
        $this->assertEquals('doctrine.dbal.conn1_connection', (string) $arguments[0]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[1]);
        $this->assertEquals('doctrine.orm.em1_configuration', (string) $arguments[1]);

        $definition = $container->getDefinition('doctrine.dbal.conn2_connection');

        $args = $definition->getArguments();
        $this->assertEquals('pdo_sqlite', $args[0]['driver']);
        $this->assertEquals('localhost', $args[0]['host']);
        $this->assertEquals('sqlite_user', $args[0]['user']);
        $this->assertEquals('doctrine.dbal.conn2_connection.configuration', (string) $args[1]);
        $this->assertEquals('doctrine.dbal.conn2_connection.event_manager', (string) $args[2]);

        $definition = $container->getDefinition('doctrine.orm.em2_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getFactoryClass());
        $this->assertEquals('create', $definition->getFactoryMethod());

        $arguments = $definition->getArguments();
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[0]);
        $this->assertEquals('doctrine.dbal.conn2_connection', (string) $arguments[0]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[1]);
        $this->assertEquals('doctrine.orm.em2_configuration', (string) $arguments[1]);

        $definition = $container->getDefinition('doctrine.orm.em1_metadata_cache');
        $this->assertEquals('%doctrine.orm.cache.xcache.class%', $definition->getClass());

        $definition = $container->getDefinition('doctrine.orm.em1_query_cache');
        $this->assertEquals('%doctrine.orm.cache.array.class%', $definition->getClass());

        $definition = $container->getDefinition('doctrine.orm.em1_result_cache');
        $this->assertEquals('%doctrine.orm.cache.array.class%', $definition->getClass());
    }

    public function testLoadLogging()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_logging');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.log_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', array(new Reference('doctrine.dbal.logger')));

        $definition = $container->getDefinition('doctrine.dbal.profile_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', array(new Reference('doctrine.dbal.logger.chain.profile')));

        $definition = $container->getDefinition('doctrine.dbal.both_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', array(new Reference('doctrine.dbal.logger.chain.both')));
    }

    public function testEntityManagerMetadataCacheDriverConfiguration()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_service_multiple_entity_managers');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.em1_metadata_cache');
        $this->assertDICDefinitionClass($definition, '%doctrine.orm.cache.xcache.class%');

        $definition = $container->getDefinition('doctrine.orm.em2_metadata_cache');
        $this->assertDICDefinitionClass($definition, '%doctrine.orm.cache.apc.class%');
    }

    public function testEntityManagerMemcacheMetadataCacheDriverConfiguration()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_service_simple_single_entity_manager');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_cache');
        $this->assertDICDefinitionClass($definition, 'Doctrine\Common\Cache\MemcacheCache');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setMemcache',
            array(new Reference('doctrine.orm.default_memcache_instance'))
        );

        $definition = $container->getDefinition('doctrine.orm.default_memcache_instance');
        $this->assertDICDefinitionClass($definition, 'Memcache');
        $this->assertDICDefinitionMethodCallOnce($definition, 'connect', array(
            'localhost', '11211'
        ));
    }

    public function testDependencyInjectionImportsOverrideDefaults()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_imports');

        $this->compileContainer($container);

        $cacheDefinition = $container->getDefinition('doctrine.orm.default_metadata_cache');
        $this->assertEquals('%doctrine.orm.cache.apc.class%', $cacheDefinition->getClass());

        $configDefinition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($configDefinition, 'setAutoGenerateProxyClasses', array('%doctrine.orm.auto_generate_proxy_classes%'));
    }

    public function testSingleEntityManagerMultipleMappingBundleDefinitions()
    {
        $container = $this->getContainer(array('YamlBundle', 'AnnotationsBundle', 'XmlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_single_em_bundle_mappings');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');

        $this->assertDICDefinitionMethodCallAt(0, $definition, 'addDriver', array(
            new Reference('doctrine.orm.default_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity'
        ));

        $this->assertDICDefinitionMethodCallAt(1, $definition, 'addDriver', array(
            new Reference('doctrine.orm.default_yml_metadata_driver'),
            'Fixtures\Bundles\YamlBundle\Entity'
        ));

        $this->assertDICDefinitionMethodCallAt(2, $definition, 'addDriver', array(
            new Reference('doctrine.orm.default_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle'
        ));

        $annDef = $container->getDefinition('doctrine.orm.default_annotation_metadata_driver');
        $this->assertDICConstructorArguments($annDef, array(
            new Reference('doctrine.orm.metadata.annotation_reader'),
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'AnnotationsBundle'.DIRECTORY_SEPARATOR.'Entity')
        ));

        $ymlDef = $container->getDefinition('doctrine.orm.default_yml_metadata_driver');
        $this->assertDICConstructorArguments($ymlDef, array(
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'YamlBundle'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'doctrine' => 'Fixtures\Bundles\YamlBundle\Entity')
        ));

        $xmlDef = $container->getDefinition('doctrine.orm.default_xml_metadata_driver');
        $this->assertDICConstructorArguments($xmlDef, array(
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'XmlBundle'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'doctrine' => 'Fixtures\Bundles\XmlBundle')
        ));
    }

    public function testMultipleEntityManagersMappingBundleDefinitions()
    {
        $container = $this->getContainer(array('YamlBundle', 'AnnotationsBundle', 'XmlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_multiple_em_bundle_mappings');

        $this->compileContainer($container);

        $this->assertEquals(array('em1' => 'doctrine.orm.em1_entity_manager', 'em2' => 'doctrine.orm.em2_entity_manager'), $container->getParameter('doctrine.entity_managers'), "Set of the existing EntityManagers names is incorrect.");
        $this->assertEquals('%doctrine.entity_managers%', $container->getDefinition('doctrine')->getArgument(2), "Set of the existing EntityManagers names is incorrect.");

        $def1 = $container->getDefinition('doctrine.orm.em1_metadata_driver');
        $def2 = $container->getDefinition('doctrine.orm.em2_metadata_driver');

        $this->assertDICDefinitionMethodCallAt(0, $def1, 'addDriver', array(
            new Reference('doctrine.orm.em1_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity'
        ));

        $this->assertDICDefinitionMethodCallAt(0, $def2, 'addDriver', array(
            new Reference('doctrine.orm.em2_yml_metadata_driver'),
            'Fixtures\Bundles\YamlBundle\Entity'
        ));

        $this->assertDICDefinitionMethodCallAt(1, $def2, 'addDriver', array(
            new Reference('doctrine.orm.em2_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle'
        ));

        $annDef = $container->getDefinition('doctrine.orm.em1_annotation_metadata_driver');
        $this->assertDICConstructorArguments($annDef, array(
            new Reference('doctrine.orm.metadata.annotation_reader'),
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'AnnotationsBundle'.DIRECTORY_SEPARATOR.'Entity')
        ));

        $ymlDef = $container->getDefinition('doctrine.orm.em2_yml_metadata_driver');
        $this->assertDICConstructorArguments($ymlDef, array(
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'YamlBundle'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'doctrine' => 'Fixtures\Bundles\YamlBundle\Entity')
        ));

        $xmlDef = $container->getDefinition('doctrine.orm.em2_xml_metadata_driver');
        $this->assertDICConstructorArguments($xmlDef, array(
            array(__DIR__ .DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Bundles'.DIRECTORY_SEPARATOR.'XmlBundle'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'doctrine' => 'Fixtures\Bundles\XmlBundle')
        ));
    }

    public function testSetTypes()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $this->loadFromFile($container, 'dbal_types');
        $this->compileContainer($container);

        $this->assertEquals(
            array('test' => array('class' => 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestType', 'commented' => true)),
            $container->getParameter('doctrine.dbal.connection_factory.types')
        );
        $this->assertEquals('%doctrine.dbal.connection_factory.types%', $container->getDefinition('doctrine.dbal.connection_factory')->getArgument(0));
    }

    public function testSetCustomFunctions()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $this->loadFromFile($container, 'orm_functions');
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomStringFunction', array('test_string', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestStringFunction'));
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomNumericFunction', array('test_numeric', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestNumericFunction'));
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomDatetimeFunction', array('test_datetime', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestDatetimeFunction'));
    }

    public function testSetNamingStrategy()
    {
        if (version_compare(Version::VERSION, "2.3.0-DEV") < 0) {
            $this->markTestSkipped('Naming Strategies are not available');
        }
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $this->loadFromFile($container, 'orm_namingstrategy');
        $this->compileContainer($container);

        $def1 = $container->getDefinition('doctrine.orm.em1_configuration');
        $def2 = $container->getDefinition('doctrine.orm.em2_configuration');

        $this->assertDICDefinitionMethodCallOnce($def1, 'setNamingStrategy', array(0 => new Reference('doctrine.orm.naming_strategy.default')));
        $this->assertDICDefinitionMethodCallOnce($def2, 'setNamingStrategy', array(0 => new Reference('doctrine.orm.naming_strategy.underscore')));
    }

    public function testSingleEMSetCustomFunctions()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $this->loadFromFile($container, 'orm_single_em_dql_functions');
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomStringFunction', array('test_string', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestStringFunction'));
    }

    public function testAddCustomHydrationMode()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $this->loadFromFile($container, 'orm_hydration_mode');
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomHydrationMode', array('test_hydrator', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestHydrator'));
    }

    public function testAddFilter()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_filters');
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $args = array(
            array('soft_delete', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\TestFilter'),
            array('myFilter', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\TestFilter')
        );
        $this->assertDICDefinitionMethodCallCount($definition, 'addFilter', $args, 2);

        $definition = $container->getDefinition('doctrine.orm.default_manager_configurator');
        $this->assertDICConstructorArguments($definition, array(array('soft_delete', 'myFilter'), array('myFilter' => array('myParameter' => 'myValue', 'mySecondParameter' => 'mySecondValue'))));

        // Let's create the instance to check the configurator work.
        /** @var $entityManager \Doctrine\ORM\EntityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $this->assertCount(2, $entityManager->getFilters()->getEnabledFilters());
    }

    public function testResolveTargetEntity()
    {
        $container = $this->getContainer(array('YamlBundle'));

        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_resolve_target_entity');
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.listeners.resolve_target_entity');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addResolveTargetEntity', array('Symfony\Component\Security\Core\User\UserInterface', 'MyUserClass', array()));
        $this->assertEquals(array('doctrine.event_listener' => array( array('event' => 'loadClassMetadata') ) ), $definition->getTags());
    }

    public function testDbalSchemaFilter()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'dbal_schema_filter');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.default_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setFilterSchemaAssetsExpression', array('^sf2_'));
    }

    public function testEntityListenerResolver()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_entity_listener_resolver');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.em1_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setEntityListenerResolver', array(new Reference('doctrine.orm.em1_entity_listener_resolver')));

        $definition = $container->getDefinition('doctrine.orm.em2_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setEntityListenerResolver', array(new Reference('doctrine.orm.em2_entity_listener_resolver')));

        $listener = $container->getDefinition('doctrine.orm.em1_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($listener, 'register', array(new Reference('entity_listener1')));

        $listener = $container->getDefinition('entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($listener, 'register', array(new Reference('entity_listener2')));
    }

    public function testRepositoryFactory()
    {
        $container = $this->getContainer();
        $loader = new DoctrineExtension();
        $container->registerExtension($loader);

        $this->loadFromFile($container, 'orm_repository_factory');

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setRepositoryFactory', array('repository_factory'));
    }

    private function getContainer($bundles = 'YamlBundle', $vendor = null)
    {
        $bundles = (array) $bundles;

        $map = array();
        foreach ($bundles as $bundle) {
            require_once __DIR__.'/Fixtures/Bundles/'.($vendor ? $vendor.'/' : '').$bundle.'/'.$bundle.'.php';

            $map[$bundle] = 'Fixtures\\Bundles\\'.($vendor ? $vendor.'\\' : '').$bundle.'\\'.$bundle;
        }

        return new ContainerBuilder(new ParameterBag(array(
            'kernel.debug'       => false,
            'kernel.bundles'     => $map,
            'kernel.cache_dir'   => sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir'    => __DIR__.'/../../' // src dir
        )));
    }

    /**
     * Assertion on the Class of a DIC Service Definition.
     *
     * @param Definition $definition
     * @param string     $expectedClass
     */
    private function assertDICDefinitionClass(Definition $definition, $expectedClass)
    {
        $this->assertEquals($expectedClass, $definition->getClass(), 'Expected Class of the DIC Container Service Definition is wrong.');
    }

    private function assertDICConstructorArguments(Definition $definition, $args)
    {
        $this->assertEquals($args, $definition->getArguments(), "Expected and actual DIC Service constructor arguments of definition '".$definition->getClass()."' don't match.");
    }

    private function assertDICDefinitionMethodCallAt($pos, Definition $definition, $methodName, array $params = null)
    {
        $calls = $definition->getMethodCalls();
        if (isset($calls[$pos][0])) {
            $this->assertEquals($methodName, $calls[$pos][0], "Method '".$methodName."' is expected to be called at position $pos.");

            if ($params !== null) {
                $this->assertEquals($params, $calls[$pos][1], "Expected parameters to methods '".$methodName."' do not match the actual parameters.");
            }
        }
    }

    /**
     * Assertion for the DI Container, check if the given definition contains a method call with the given parameters.
     *
     * @param Definition $definition
     * @param string     $methodName
     * @param array      $params
     */
    private function assertDICDefinitionMethodCallOnce(Definition $definition, $methodName, array $params = null)
    {
        $calls = $definition->getMethodCalls();
        $called = false;
        foreach ($calls as $call) {
            if ($call[0] == $methodName) {
                if ($called) {
                    $this->fail("Method '".$methodName."' is expected to be called only once, a second call was registered though.");
                } else {
                    $called = true;
                    if ($params !== null) {
                        $this->assertEquals($params, $call[1], "Expected parameters to methods '".$methodName."' do not match the actual parameters.");
                    }
                }
            }
        }
        if (!$called) {
            $this->fail("Method '".$methodName."' is expected to be called once, definition does not contain a call though.");
        }
    }

    private function assertDICDefinitionMethodCallCount(Definition $definition, $methodName, array $params = array(), $nbCalls = 1)
    {
        $calls = $definition->getMethodCalls();
        $called = 0;
        foreach ($calls as $call) {
            if ($call[0] == $methodName) {
                if ($called > $nbCalls) {
                    break;
                }

                if (isset($params[$called])) {
                    $this->assertEquals($params[$called], $call[1], "Expected parameters to methods '".$methodName."' do not match the actual parameters.");
                }
                $called++;
            }
        }

        $this->assertEquals($nbCalls, $called, sprintf('The method "%s" should be called %d times', $methodName, $nbCalls));
    }

    private function compileContainer(ContainerBuilder $container)
    {
        $container->getCompilerPassConfig()->setOptimizationPasses(array(new ResolveDefinitionTemplatesPass()));
        $container->getCompilerPassConfig()->setRemovingPasses(array());
        $container->compile();
    }
}
