<?php

namespace Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DbalSchemaFilterPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\EntityListenerPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\WellKnownSchemaFilterPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\RegisterEventListenersAndSubscribersPass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\DoctrineProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ResolveChildDefinitionsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function assert;

abstract class AbstractDoctrineExtensionTest extends TestCase
{
    abstract protected function loadFromFile(ContainerBuilder $container, string $file): void;

    public function testDbalLoadFromXmlMultipleConnections(): void
    {
        $container = $this->loadContainer('dbal_service_multiple_connections');

        // doctrine.dbal.mysql_connection
        $config = $container->getDefinition('doctrine.dbal.mysql_connection')->getArgument(0);

        $this->assertEquals('mysql_s3cr3t', $config['password']);
        $this->assertEquals('mysql_user', $config['user']);
        $this->assertEquals('mysql_db', $config['dbname']);
        $this->assertEquals('/path/to/mysqld.sock', $config['unix_socket']);

        // doctrine.dbal.sqlite_connection
        $config = $container->getDefinition('doctrine.dbal.sqlite_connection')->getArgument(0);
        $this->assertSame('pdo_sqlite', $config['driver']);
        $this->assertSame('sqlite_db', $config['dbname']);
        $this->assertSame('sqlite_user', $config['user']);
        $this->assertSame('sqlite_s3cr3t', $config['password']);
        $this->assertSame('/tmp/db.sqlite', $config['path']);
        $this->assertTrue($config['memory']);
        $this->assertSame(['asin' => ['callback' => 'asin', 'numArgs' => 1]], $config['driverOptions']['userDefinedFunctions']);
        $this->assertSame('foo', $config['driverOptions']['arbitraryValue']);

        // doctrine.dbal.oci8_connection
        $config = $container->getDefinition('doctrine.dbal.oci_connection')->getArgument(0);
        $this->assertSame('oci8', $config['driver']);
        $this->assertSame('oracle_db', $config['dbname']);
        $this->assertSame('oracle_user', $config['user']);
        $this->assertSame('oracle_s3cr3t', $config['password']);
        $this->assertSame('oracle_service', $config['servicename']);
        $this->assertTrue($config['service']);
        $this->assertTrue($config['pooled']);
        $this->assertSame('utf8', $config['charset']);

        // doctrine.dbal.ibmdb2_connection
        $config = $container->getDefinition('doctrine.dbal.ibmdb2_connection')->getArgument(0);
        $this->assertSame('ibm_db2', $config['driver']);
        $this->assertSame('ibmdb2_db', $config['dbname']);
        $this->assertSame('ibmdb2_user', $config['user']);
        $this->assertSame('ibmdb2_s3cr3t', $config['password']);
        $this->assertSame('TCPIP', $config['protocol']);

        // doctrine.dbal.pgsql_connection
        $config = $container->getDefinition('doctrine.dbal.pgsql_connection')->getArgument(0);
        $this->assertSame('pdo_pgsql', $config['driver']);
        $this->assertSame('pgsql_schema', $config['dbname']);
        $this->assertSame('pgsql_user', $config['user']);
        $this->assertSame('pgsql_s3cr3t', $config['password']);
        $this->assertSame('pgsql_db', $config['default_dbname']);
        $this->assertSame('require', $config['sslmode']);
        $this->assertSame('postgresql-ca.pem', $config['sslrootcert']);
        $this->assertSame('postgresql-cert.pem', $config['sslcert']);
        $this->assertSame('postgresql-key.pem', $config['sslkey']);
        $this->assertSame('postgresql.crl', $config['sslcrl']);
        $this->assertSame('utf8', $config['charset']);

        // doctrine.dbal.sqlanywhere_connection
        $config = $container->getDefinition('doctrine.dbal.sqlanywhere_connection')->getArgument(0);
        $this->assertSame('sqlanywhere', $config['driver']);
        $this->assertSame('localhost', $config['host']);
        $this->assertSame(2683, $config['port']);
        $this->assertSame('sqlanywhere_server', $config['server']);
        $this->assertSame('sqlanywhere_db', $config['dbname']);
        $this->assertSame('sqlanywhere_user', $config['user']);
        $this->assertSame('sqlanywhere_s3cr3t', $config['password']);
        $this->assertTrue($config['persistent']);
        $this->assertSame('utf8', $config['charset']);
    }

    public function testDbalLoadFromXmlSingleConnections(): void
    {
        $container = $this->loadContainer('dbal_service_single_connection');

        // doctrine.dbal.mysql_connection
        $config = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('mysql_s3cr3t', $config['password']);
        $this->assertEquals('mysql_user', $config['user']);
        $this->assertEquals('mysql_db', $config['dbname']);
        $this->assertEquals('/path/to/mysqld.sock', $config['unix_socket']);
        $this->assertEquals('5.6.20', $config['serverVersion']);
    }

    public function testDbalLoadSingleMasterSlaveConnection(): void
    {
        $container = $this->loadContainer('dbal_service_single_master_slave_connection');

        // doctrine.dbal.mysql_connection
        $param = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals(
            class_exists(PrimaryReadReplicaConnection::class) ?
            PrimaryReadReplicaConnection::class : // dbal >= 2.11
            MasterSlaveConnection::class, // dbal < 2.11,x
            $param['wrapperClass']
        );
        $this->assertTrue($param['keepReplica']);
        $this->assertEquals(
            [
                'user' => 'mysql_user',
                'password' => 'mysql_s3cr3t',
                'port' => null,
                'dbname' => 'mysql_db',
                'host' => 'localhost',
                'unix_socket' => '/path/to/mysqld.sock',
            ],
            $param['primary'] ?? $param['master'] // TODO: Remove 'master' support here when we require dbal >= 2.11
        );
        $this->assertEquals(
            [
                'user' => 'replica_user',
                'password' => 'replica_s3cr3t',
                'port' => null,
                'dbname' => 'replica_db',
                'host' => 'localhost',
                'unix_socket' => '/path/to/mysqld_replica.sock',
            ],
            $param['replicas']['replica1']
        );
        $this->assertEquals(['engine' => 'InnoDB'], $param['defaultTableOptions']);
    }

    public function testDbalLoadPoolShardingConnection(): void
    {
        $container = $this->loadContainer('dbal_service_pool_sharding_connection');

        // doctrine.dbal.mysql_connection
        $param = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('Doctrine\\DBAL\\Sharding\\PoolingShardConnection', $param['wrapperClass']);
        $this->assertEquals(new Reference('foo.shard_choser'), $param['shardChoser']);
        $this->assertEquals(
            [
                'user' => 'mysql_user',
                'password' => 'mysql_s3cr3t',
                'port' => null,
                'dbname' => 'mysql_db',
                'host' => 'localhost',
                'unix_socket' => '/path/to/mysqld.sock',
            ],
            $param['global']
        );
        $this->assertEquals(
            [
                'user' => 'shard_user',
                'password' => 'shard_s3cr3t',
                'port' => null,
                'dbname' => 'shard_db',
                'host' => 'localhost',
                'unix_socket' => '/path/to/mysqld_shard.sock',
                'id' => 1,
            ],
            $param['shards'][0]
        );
        $this->assertEquals(['engine' => 'InnoDB'], $param['defaultTableOptions']);
    }

    public function testDbalLoadSavepointsForNestedTransactions(): void
    {
        $container = $this->loadContainer('dbal_savepoints');

        $calls = $container->getDefinition('doctrine.dbal.savepoints_connection')->getMethodCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('setNestTransactionsWithSavepoints', $calls[0][0]);
        $this->assertTrue($calls[0][1][0]);

        $calls = $container->getDefinition('doctrine.dbal.nosavepoints_connection')->getMethodCalls();
        $this->assertCount(0, $calls);

        $calls = $container->getDefinition('doctrine.dbal.notset_connection')->getMethodCalls();
        $this->assertCount(0, $calls);
    }

    public function testLoadSimpleSingleConnection(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_service_simple_single_entity_manager');

        $definition = $container->getDefinition('doctrine.dbal.default_connection');

        $this->assertDICConstructorArguments($definition, [
            [
                'dbname' => 'db',
                'host' => 'localhost',
                'port' => null,
                'user' => 'root',
                'password' => null,
                'driver' => 'pdo_mysql',
                'driverOptions' => [],
                'defaultTableOptions' => [],
            ],
            new Reference('doctrine.dbal.default_connection.configuration'),
            new Reference('doctrine.dbal.default_connection.event_manager'),
            [],
        ]);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);
    }

    /**
     * The PDO driver doesn't require a database name to be to set when connecting to a database server
     */
    public function testLoadSimpleSingleConnectionWithoutDbName(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_service_simple_single_entity_manager_without_dbname');

        $definition = $container->getDefinition('doctrine.dbal.default_connection');
        assert($definition instanceof Definition);

        $this->assertDICConstructorArguments($definition, [
            [
                'host' => 'localhost',
                'port' => null,
                'user' => 'root',
                'password' => null,
                'driver' => 'pdo_mysql',
                'driverOptions' => [],
                'defaultTableOptions' => [],
            ],
            new Reference('doctrine.dbal.default_connection.configuration'),
            new Reference('doctrine.dbal.default_connection.event_manager'),
            [],
        ]);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $factory = $definition->getFactory();

        $this->assertEquals('%doctrine.orm.entity_manager.class%', $factory[0]);
        $this->assertEquals('create', $factory[1]);

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);
    }

    public function testLoadSingleConnection(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_service_single_entity_manager');

        $definition = $container->getDefinition('doctrine.dbal.default_connection');

        $this->assertDICConstructorArguments($definition, [
            [
                'host' => 'localhost',
                'driver' => 'pdo_sqlite',
                'driverOptions' => [],
                'user' => 'sqlite_user',
                'port' => null,
                'password' => 'sqlite_s3cr3t',
                'dbname' => 'sqlite_db',
                'memory' => true,
                'defaultTableOptions' => [],
            ],
            new Reference('doctrine.dbal.default_connection.configuration'),
            new Reference('doctrine.dbal.default_connection.event_manager'),
            [],
        ]);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);

        $configDef = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($configDef, 'setDefaultRepositoryClassName', ['Acme\Doctrine\Repository']);
    }

    public function testLoadMultipleConnections(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_service_multiple_entity_managers');

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
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

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
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $arguments = $definition->getArguments();
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[0]);
        $this->assertEquals('doctrine.dbal.conn2_connection', (string) $arguments[0]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[1]);
        $this->assertEquals('doctrine.orm.em2_configuration', (string) $arguments[1]);

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.em1_metadata_cache'));
        $this->assertEquals(DoctrineProvider::class, $definition->getClass());

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.em1_query_cache'));
        $this->assertEquals(DoctrineProvider::class, $definition->getClass());
        $arguments = $definition->getArguments();
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertEquals('cache.doctrine.orm.em1.query', (string) $arguments[0]);

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.em1_result_cache'));
        $this->assertEquals(DoctrineProvider::class, $definition->getClass());
        $arguments = $definition->getArguments();
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertEquals('cache.doctrine.orm.em1.result', (string) $arguments[0]);
    }

    public function testLoadLogging(): void
    {
        $container = $this->loadContainer('dbal_logging');

        $definition = $container->getDefinition('doctrine.dbal.log_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', [new Reference('doctrine.dbal.logger')]);

        $definition = $container->getDefinition('doctrine.dbal.profile_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', [new Reference('doctrine.dbal.logger.profiling.profile')]);

        $definition = $container->getDefinition('doctrine.dbal.profile_with_backtrace_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', [new Reference('doctrine.dbal.logger.backtrace.profile_with_backtrace')]);

        $definition = $container->getDefinition('doctrine.dbal.backtrace_without_profile_connection.configuration');
        $this->assertDICDefinitionNoMethodCall($definition, 'setSQLLogger');

        $definition = $container->getDefinition('doctrine.dbal.both_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setSQLLogger', [new Reference('doctrine.dbal.logger.chain.both')]);
    }

    public function testEntityManagerMetadataCacheDriverConfiguration(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_service_multiple_entity_managers');

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.em1_metadata_cache'));
        $this->assertDICDefinitionClass($definition, DoctrineProvider::class);

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.em2_metadata_cache'));
        $this->assertDICDefinitionClass($definition, DoctrineProvider::class);
    }

    public function testDependencyInjectionImportsOverrideDefaults(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_imports');

        $cacheDefinition = $container->getDefinition((string) $container->getAlias('doctrine.orm.default_metadata_cache'));
        $this->assertEquals(DoctrineProvider::class, $cacheDefinition->getClass());

        $configDefinition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($configDefinition, 'setAutoGenerateProxyClasses', ['%doctrine.orm.auto_generate_proxy_classes%']);
    }

    public function testSingleEntityManagerMultipleMappingBundleDefinitions(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_single_em_bundle_mappings', ['YamlBundle', 'AnnotationsBundle', 'XmlBundle']);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');

        $this->assertDICDefinitionMethodCallAt(0, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity',
        ]);

        $this->assertDICDefinitionMethodCallAt(1, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_yml_metadata_driver'),
            'Fixtures\Bundles\YamlBundle\Entity',
        ]);

        $this->assertDICDefinitionMethodCallAt(2, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle',
        ]);

        $annDef = $container->getDefinition('doctrine.orm.default_annotation_metadata_driver');
        $this->assertDICConstructorArguments($annDef, [
            new Reference('doctrine.orm.metadata.annotation_reader'),
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'AnnotationsBundle' . DIRECTORY_SEPARATOR . 'Entity'],
        ]);

        $ymlDef = $container->getDefinition('doctrine.orm.default_yml_metadata_driver');
        $this->assertDICConstructorArguments($ymlDef, [
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'YamlBundle' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'doctrine' => 'Fixtures\Bundles\YamlBundle\Entity'],
        ]);

        $xmlDef = $container->getDefinition('doctrine.orm.default_xml_metadata_driver');
        $this->assertDICConstructorArguments($xmlDef, [
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'XmlBundle' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'doctrine' => 'Fixtures\Bundles\XmlBundle'],
        ]);
    }

    public function testMultipleEntityManagersMappingBundleDefinitions(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_multiple_em_bundle_mappings', ['YamlBundle', 'AnnotationsBundle', 'XmlBundle']);

        $this->assertEquals(['em1' => 'doctrine.orm.em1_entity_manager', 'em2' => 'doctrine.orm.em2_entity_manager'], $container->getParameter('doctrine.entity_managers'), 'Set of the existing EntityManagers names is incorrect.');
        $this->assertEquals('%doctrine.entity_managers%', $container->getDefinition('doctrine')->getArgument(2), 'Set of the existing EntityManagers names is incorrect.');

        $def1 = $container->getDefinition('doctrine.orm.em1_metadata_driver');
        $def2 = $container->getDefinition('doctrine.orm.em2_metadata_driver');

        $this->assertDICDefinitionMethodCallAt(0, $def1, 'addDriver', [
            new Reference('doctrine.orm.em1_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity',
        ]);

        $this->assertDICDefinitionMethodCallAt(0, $def2, 'addDriver', [
            new Reference('doctrine.orm.em2_yml_metadata_driver'),
            'Fixtures\Bundles\YamlBundle\Entity',
        ]);

        $this->assertDICDefinitionMethodCallAt(1, $def2, 'addDriver', [
            new Reference('doctrine.orm.em2_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle',
        ]);

        $annDef = $container->getDefinition('doctrine.orm.em1_annotation_metadata_driver');
        $this->assertDICConstructorArguments($annDef, [
            new Reference('doctrine.orm.metadata.annotation_reader'),
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'AnnotationsBundle' . DIRECTORY_SEPARATOR . 'Entity'],
        ]);

        $ymlDef = $container->getDefinition('doctrine.orm.em2_yml_metadata_driver');
        $this->assertDICConstructorArguments($ymlDef, [
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'YamlBundle' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'doctrine' => 'Fixtures\Bundles\YamlBundle\Entity'],
        ]);

        $xmlDef = $container->getDefinition('doctrine.orm.em2_xml_metadata_driver');
        $this->assertDICConstructorArguments($xmlDef, [
            [__DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'Bundles' . DIRECTORY_SEPARATOR . 'XmlBundle' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'doctrine' => 'Fixtures\Bundles\XmlBundle'],
        ]);
    }

    public function testSingleEntityManagerDefaultTableOptions(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_single_em_default_table_options', ['YamlBundle', 'AnnotationsBundle', 'XmlBundle']);

        $param = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertArrayHasKey('defaultTableOptions', $param);

        $defaults = $param['defaultTableOptions'];

        $this->assertArrayHasKey('charset', $defaults);
        $this->assertArrayHasKey('collate', $defaults);
        $this->assertArrayHasKey('engine', $defaults);

        $this->assertEquals('utf8mb4', $defaults['charset']);
        $this->assertEquals('utf8mb4_unicode_ci', $defaults['collate']);
        $this->assertEquals('InnoDB', $defaults['engine']);
    }

    public function testSetTypes(): void
    {
        $container = $this->loadContainer('dbal_types');

        $this->assertEquals(
            ['test' => ['class' => TestType::class]],
            $container->getParameter('doctrine.dbal.connection_factory.types')
        );
        $this->assertEquals('%doctrine.dbal.connection_factory.types%', $container->getDefinition('doctrine.dbal.connection_factory')->getArgument(0));
    }

    public function testSetCustomFunctions(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_functions');

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomStringFunction', ['test_string', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestStringFunction']);
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomNumericFunction', ['test_numeric', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestNumericFunction']);
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomDatetimeFunction', ['test_datetime', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestDatetimeFunction']);
    }

    public function testSetNamingStrategy(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_namingstrategy');

        $def1 = $container->getDefinition('doctrine.orm.em1_configuration');
        $def2 = $container->getDefinition('doctrine.orm.em2_configuration');

        $this->assertDICDefinitionMethodCallOnce($def1, 'setNamingStrategy', [0 => new Reference('doctrine.orm.naming_strategy.default')]);
        $this->assertDICDefinitionMethodCallOnce($def2, 'setNamingStrategy', [0 => new Reference('doctrine.orm.naming_strategy.underscore')]);
    }

    public function testSetQuoteStrategy(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_quotestrategy');

        $def1 = $container->getDefinition('doctrine.orm.em1_configuration');
        $def2 = $container->getDefinition('doctrine.orm.em2_configuration');

        $this->assertDICDefinitionMethodCallOnce($def1, 'setQuoteStrategy', [0 => new Reference('doctrine.orm.quote_strategy.default')]);
        $this->assertDICDefinitionMethodCallOnce($def2, 'setQuoteStrategy', [0 => new Reference('doctrine.orm.quote_strategy.ansi')]);
    }

    public function testSecondLevelCache(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_second_level_cache');

        $this->assertTrue($container->has('doctrine.orm.default_configuration'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.cache_configuration'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.region_cache_driver'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.regions_configuration'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.default_cache_factory'));

        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.logger_chain'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.logger_statistics'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.logger.my_service_logger1'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.logger.my_service_logger2'));

        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.region.my_entity_region'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.region.my_service_region'));
        $this->assertTrue($container->has('doctrine.orm.default_second_level_cache.region.my_query_region_filelock'));

        $slcFactoryDef       = $container->getDefinition('doctrine.orm.default_second_level_cache.default_cache_factory');
        $slcRegionsConfDef   = $container->getDefinition('doctrine.orm.default_second_level_cache.regions_configuration');
        $myEntityRegionDef   = $container->getDefinition('doctrine.orm.default_second_level_cache.region.my_entity_region');
        $loggerChainDef      = $container->getDefinition('doctrine.orm.default_second_level_cache.logger_chain');
        $loggerStatisticsDef = $container->getDefinition('doctrine.orm.default_second_level_cache.logger_statistics');
        $myQueryRegionDef    = $container->getDefinition('doctrine.orm.default_second_level_cache.region.my_query_region_filelock');
        $cacheDriverDef      = $container->getDefinition((string) $container->getAlias('doctrine.orm.default_second_level_cache.region_cache_driver'));
        $configDef           = $container->getDefinition('doctrine.orm.default_configuration');
        $slcRegionsConfArgs  = $slcRegionsConfDef->getArguments();
        $myEntityRegionArgs  = $myEntityRegionDef->getArguments();
        $myQueryRegionArgs   = $myQueryRegionDef->getArguments();
        $slcFactoryArgs      = $slcFactoryDef->getArguments();

        $this->assertDICDefinitionClass($slcFactoryDef, '%doctrine.orm.second_level_cache.default_cache_factory.class%');
        $this->assertDICDefinitionClass($slcRegionsConfDef, '%doctrine.orm.second_level_cache.regions_configuration.class%');
        $this->assertDICDefinitionClass($myQueryRegionDef, '%doctrine.orm.second_level_cache.filelock_region.class%');
        $this->assertDICDefinitionClass($myEntityRegionDef, '%doctrine.orm.second_level_cache.default_region.class%');
        $this->assertDICDefinitionClass($loggerChainDef, '%doctrine.orm.second_level_cache.logger_chain.class%');
        $this->assertDICDefinitionClass($loggerStatisticsDef, '%doctrine.orm.second_level_cache.logger_statistics.class%');
        $this->assertDICDefinitionClass($cacheDriverDef, DoctrineProvider::class);
        $this->assertDICDefinitionMethodCallOnce($configDef, 'setSecondLevelCacheConfiguration');
        $this->assertDICDefinitionMethodCallCount($slcFactoryDef, 'setRegion', [], 3);
        $this->assertDICDefinitionMethodCallCount($loggerChainDef, 'setLogger', [], 3);

        $this->assertEquals([3600, 60], $slcRegionsConfArgs);

        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $slcFactoryArgs[0]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $slcFactoryArgs[1]);

        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $myEntityRegionArgs[1]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $myQueryRegionArgs[0]);

        $this->assertEquals('my_entity_region', $myEntityRegionArgs[0]);
        $this->assertEquals('doctrine.orm.default_second_level_cache.region.my_entity_region_driver', $myEntityRegionArgs[1]);
        $this->assertEquals(600, $myEntityRegionArgs[2]);

        $this->assertEquals('doctrine.orm.default_second_level_cache.region.my_query_region', $myQueryRegionArgs[0]);
        $this->assertStringContainsString(
            '/doctrine/orm/slc/filelock',
            $myQueryRegionArgs[1]
        );
        $this->assertEquals(60, $myQueryRegionArgs[2]);

        $this->assertEquals('doctrine.orm.default_second_level_cache.regions_configuration', $slcFactoryArgs[0]);
        $this->assertEquals('doctrine.orm.default_second_level_cache.region_cache_driver', $slcFactoryArgs[1]);
    }

    public function testSingleEMSetCustomFunctions(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_single_em_dql_functions');

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomStringFunction', ['test_string', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestStringFunction']);
    }

    public function testAddCustomHydrationMode(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_hydration_mode');

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addCustomHydrationMode', ['test_hydrator', 'Symfony\Bundle\DoctrineBundle\Tests\DependencyInjection\TestHydrator']);
    }

    public function testAddFilter(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_filters');

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $args       = [
            ['soft_delete', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\TestFilter'],
            ['myFilter', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\TestFilter'],
        ];
        $this->assertDICDefinitionMethodCallCount($definition, 'addFilter', $args, 2);

        $definition = $container->getDefinition('doctrine.orm.default_manager_configurator');
        $this->assertDICConstructorArguments($definition, [['soft_delete', 'myFilter'], ['myFilter' => ['myParameter' => 'myValue', 'mySecondParameter' => 'mySecondValue']]]);

        $entityManager = $container->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManager);
        $this->assertCount(2, $entityManager->getFilters()->getEnabledFilters());
    }

    public function testResolveTargetEntity(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_resolve_target_entity');

        $definition = $container->getDefinition('doctrine.orm.listeners.resolve_target_entity');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addResolveTargetEntity', ['Symfony\Component\Security\Core\User\UserInterface', 'MyUserClass', []]);

        $tags = $definition->getTags();
        unset($tags['container.no_preload']);
        $this->assertEquals(['doctrine.event_subscriber' => [[]]], $tags);
    }

    public function testAttachEntityListeners(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_attach_entity_listener');

        $definition  = $container->getDefinition('doctrine.orm.default_listeners.attach_entity_listeners');
        $methodCalls = $definition->getMethodCalls();

        $this->assertDICDefinitionMethodCallCount($definition, 'addEntityListener', [], 6);
        $tags = $definition->getTags();
        unset($tags['container.no_preload']);
        $this->assertEquals(['doctrine.event_listener' => [['event' => 'loadClassMetadata']]], $tags);

        $this->assertEquals($methodCalls[0], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\FooEntity',
                'MyBundles\Listeners\FooEntityListener',
                'prePersist',
                null,
            ],
        ]);

        $this->assertEquals($methodCalls[1], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\FooEntity',
                'MyBundles\Listeners\FooEntityListener',
                'postPersist',
                'postPersist',
            ],
        ]);

        $this->assertEquals($methodCalls[2], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\FooEntity',
                'MyBundles\Listeners\FooEntityListener',
                'postLoad',
                'postLoadHandler',
            ],
        ]);

        $this->assertEquals($methodCalls[3], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\BarEntity',
                'MyBundles\Listeners\BarEntityListener',
                'prePersist',
                'prePersist',
            ],
        ]);

        $this->assertEquals($methodCalls[4], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\BarEntity',
                'MyBundles\Listeners\BarEntityListener',
                'prePersist',
                'prePersistHandler',
            ],
        ]);

        $this->assertEquals($methodCalls[5], [
            'addEntityListener',
            [
                'ExternalBundles\Entities\BarEntity',
                'MyBundles\Listeners\LogDeleteEntityListener',
                'postDelete',
                'postDelete',
            ],
        ]);
    }

    public function testDbalAutoCommit(): void
    {
        $container = $this->loadContainer('dbal_auto_commit');

        $definition = $container->getDefinition('doctrine.dbal.default_connection.configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setAutoCommit', [false]);
    }

    public function testDbalOracleConnectstring(): void
    {
        $container = $this->loadContainer('dbal_oracle_connectstring');

        $config = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);
        $this->assertSame('scott@sales-server:1521/sales.us.example.com', $config['connectstring']);
    }

    public function testDbalOracleInstancename(): void
    {
        $container = $this->loadContainer('dbal_oracle_instancename');

        $config = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);
        $this->assertSame('mySuperInstance', $config['instancename']);
    }

    public function testDbalSchemaFilterNewConfig(): void
    {
        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new WellKnownSchemaFilterPass());
        $container->addCompilerPass(new DbalSchemaFilterPass());

        // ignore table1 table on "default" connection
        $container->register('dummy_filter1', DummySchemaAssetsFilter::class)
            ->setArguments(['table1'])
            ->addTag('doctrine.dbal.schema_filter');

        // ignore table2 table on "connection2" connection
        $container->register('dummy_filter2', DummySchemaAssetsFilter::class)
            ->setArguments(['table2'])
            ->addTag('doctrine.dbal.schema_filter', ['connection' => 'connection2']);

        $this->loadFromFile($container, 'dbal_schema_filter');

        $assetNames               = ['table1', 'table2', 'table3', 't_ignored'];
        $expectedConnectionAssets = [
            // ignores table1 + schema_filter applies
            'connection1' => ['table2', 'table3'],
            // ignores table2, no schema_filter applies
            'connection2' => ['table1', 'table3', 't_ignored'],
            // connection3 has no ignores, handled separately
        ];

        $this->compileContainer($container);

        $getConfiguration = static function (string $connectionName) use ($container): Configuration {
            return $container->get(sprintf('doctrine.dbal.%s_connection', $connectionName))->getConfiguration();
        };

        foreach ($expectedConnectionAssets as $connectionName => $expectedTables) {
            $connConfig = $getConfiguration($connectionName);
            $this->assertSame($expectedTables, array_values(array_filter($assetNames, $connConfig->getSchemaAssetsFilter())), sprintf('Filtering for connection "%s"', $connectionName));
        }

        $this->assertNull($connConfig = $getConfiguration('connection3')->getSchemaAssetsFilter());
    }

    public static function dataWellKnownSchemaFilterServices()
    {
        yield ['cache', 'cache_items'];
        yield ['lock', 'lock_keys'];
        yield ['messenger', 'messenger_messages'];
        yield ['messenger_legacy', 'messenger_messages'];
        yield ['session', 'sessions'];
    }

    /**
     * @dataProvider dataWellKnownSchemaFilterServices
     */
    public function testWellKnownSchemaFilterDefaultTables(string $fileName, string $tableName): void
    {
        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new WellKnownSchemaFilterPass());
        $container->addCompilerPass(new DbalSchemaFilterPass());

        $this->loadFromFile($container, 'well_known_schema_filter_default_tables_' . $fileName);

        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.well_known_schema_asset_filter');

        $this->assertSame([[$tableName]], $definition->getArguments());
        $this->assertSame([['connection' => 'connection1'], ['connection' => 'connection2'], ['connection' => 'connection3']], $definition->getTag('doctrine.dbal.schema_filter'));

        $definition = $container->getDefinition('doctrine.dbal.connection1_schema_asset_filter_manager');

        $this->assertEquals([new Reference('doctrine.dbal.well_known_schema_asset_filter'), new Reference('doctrine.dbal.connection1_regex_schema_filter')], $definition->getArgument(0));

        $filter = $container->get('well_known_filter');

        $this->assertFalse($filter($tableName));
        $this->assertTrue($filter('anything_else'));
    }

    public static function dataWellKnownSchemaOverriddenTablesFilterServices()
    {
        yield ['cache', 'app_cache'];
        yield ['lock', 'app_locks'];
        yield ['messenger', 'app_messages'];
        yield ['session', 'app_session'];
    }

    /**
     * @dataProvider dataWellKnownSchemaOverriddenTablesFilterServices
     */
    public function testWellKnownSchemaFilterOverriddenTables(string $fileName, string $tableName): void
    {
        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new WellKnownSchemaFilterPass());
        $container->addCompilerPass(new DbalSchemaFilterPass());

        $this->loadFromFile($container, 'well_known_schema_filter_overridden_tables_' . $fileName);

        $this->compileContainer($container);

        $filter = $container->get('well_known_filter');

        $this->assertFalse($filter($tableName));
    }

    public function testEntityListenerResolver(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_entity_listener_resolver', ['YamlBundle'], new EntityListenerPass());

        $definition = $container->getDefinition('doctrine.orm.em1_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setEntityListenerResolver', [new Reference('doctrine.orm.em1_entity_listener_resolver')]);

        $definition = $container->getDefinition('doctrine.orm.em2_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setEntityListenerResolver', [new Reference('doctrine.orm.em2_entity_listener_resolver')]);

        $listener = $container->getDefinition('doctrine.orm.em1_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($listener, 'registerService', ['EntityListener', 'entity_listener1']);

        $listener = $container->getDefinition('entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($listener, 'register', [new Reference('entity_listener2')]);
    }

    public function testAttachEntityListenerTag(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_attach_entity_listener_tag');

        $this->compileContainer($container);

        $listener = $container->getDefinition('doctrine.orm.em1_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallCount($listener, 'registerService', [
            ['ParentEntityListener', 'children_entity_listener'],
            ['EntityListener1', 'entity_listener1'],
            ['Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Fixtures\InvokableEntityListener', 'invokable_entity_listener'],
            ['Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Fixtures\InvokableEntityListener', 'invokable_entity_listener'],
        ], 4);

        $listener = $container->getDefinition('doctrine.orm.em2_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($listener, 'registerService', ['EntityListener2', 'entity_listener2']);

        $attachListener = $container->getDefinition('doctrine.orm.em1_listeners.attach_entity_listeners');
        $this->assertDICDefinitionMethodCallAt(1, $attachListener, 'addEntityListener', ['My/Entity1', 'EntityListener1', 'postLoad']);
        $this->assertDICDefinitionMethodCallAt(2, $attachListener, 'addEntityListener', ['My/Entity1', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Fixtures\InvokableEntityListener', 'loadClassMetadata', '__invoke']);
        $this->assertDICDefinitionMethodCallAt(3, $attachListener, 'addEntityListener', ['My/Entity1', 'Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Fixtures\InvokableEntityListener', 'postPersist']);
        $this->assertDICDefinitionMethodCallAt(0, $attachListener, 'addEntityListener', ['My/Entity3', 'ParentEntityListener', 'postLoad']);

        $attachListener = $container->getDefinition('doctrine.orm.em2_listeners.attach_entity_listeners');
        $this->assertDICDefinitionMethodCallOnce($attachListener, 'addEntityListener', ['My/Entity2', 'EntityListener2', 'preFlush', 'preFlushHandler']);
    }

    public function testAttachEntityListenersTwoConnections(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['YamlBundle']);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new RegisterEventListenersAndSubscribersPass('doctrine.connections', 'doctrine.dbal.%s_connection.event_manager', 'doctrine'));

        $this->loadFromFile($container, 'orm_attach_entity_listeners_two_connections');

        $this->compileContainer($container);

        $defaultEventManager = $container->getDefinition('doctrine.dbal.default_connection.event_manager');
        $this->assertDICDefinitionNoMethodCall($defaultEventManager, 'addEventListener', [['loadClassMetadata'], new Reference('doctrine.orm.em2_listeners.attach_entity_listeners')]);
        $this->assertDICDefinitionMethodCallOnce($defaultEventManager, 'addEventListener', [['loadClassMetadata'], new Reference('doctrine.orm.em1_listeners.attach_entity_listeners')]);

        $foobarEventManager = $container->getDefinition('doctrine.dbal.foobar_connection.event_manager');
        $this->assertDICDefinitionNoMethodCall($foobarEventManager, 'addEventListener', [['loadClassMetadata'], new Reference('doctrine.orm.em1_listeners.attach_entity_listeners')]);
        $this->assertDICDefinitionMethodCallOnce($foobarEventManager, 'addEventListener', [['loadClassMetadata'], new Reference('doctrine.orm.em2_listeners.attach_entity_listeners')]);
    }

    public function testAttachLazyEntityListener(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_attach_lazy_entity_listener');

        $this->compileContainer($container);

        $resolver1 = $container->getDefinition('doctrine.orm.em1_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallAt(0, $resolver1, 'registerService', ['EntityListener1', 'entity_listener1']);
        $this->assertDICDefinitionMethodCallAt(1, $resolver1, 'register', [new Reference('entity_listener3')]);
        $this->assertDICDefinitionMethodCallAt(2, $resolver1, 'registerService', ['EntityListener4', 'entity_listener4']);

        $serviceLocatorReference = $resolver1->getArgument(0);
        $this->assertInstanceOf(Reference::class, $serviceLocatorReference);
        $serviceLocatorDefinition = $container->getDefinition((string) $serviceLocatorReference);
        $this->assertSame(ServiceLocator::class, $serviceLocatorDefinition->getClass());
        $serviceLocatorMap = $serviceLocatorDefinition->getArgument(0);
        $this->assertSame(['entity_listener1', 'entity_listener4'], array_keys($serviceLocatorMap));

        $resolver2 = $container->findDefinition('custom_entity_listener_resolver');
        $this->assertDICDefinitionMethodCallOnce($resolver2, 'registerService', ['EntityListener2', 'entity_listener2']);
    }

    public function testAttachLazyEntityListenerForCustomResolver(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_entity_listener_custom_resolver');

        $this->compileContainer($container);

        $resolver = $container->getDefinition('custom_entity_listener_resolver');
        $this->assertTrue($resolver->isPublic());
        $this->assertEmpty($resolver->getArguments(), 'We must not change the arguments for custom services.');
        $this->assertDICDefinitionMethodCallOnce($resolver, 'registerService', ['EntityListener', 'entity_listener']);
        $this->assertTrue($container->getDefinition('entity_listener')->isPublic());
    }

    public function testLazyEntityListenerResolverWithoutCorrectInterface(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_entity_listener_lazy_resolver_without_interface');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EntityListenerServiceResolver');
        $this->compileContainer($container);
    }

    public function testPrivateLazyEntityListener(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_entity_listener_lazy_private');

        $this->compileContainer($container);

        $this->assertTrue($container->getDefinition('doctrine.orm.em1_entity_listener_resolver')->isPublic());
    }

    public function testAbstractEntityListener(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([]);
        $loader    = new DoctrineExtension();
        $container->registerExtension($loader);
        $container->addCompilerPass(new EntityListenerPass());

        $this->loadFromFile($container, 'orm_entity_listener_abstract');

        $this->expectException(InvalidArgumentException::class);
        if (method_exists($this, 'expectExceptionMessageMatches')) {
            $this->expectExceptionMessageMatches('/The service ".*" must not be abstract\./');
        } else {
            $this->expectExceptionMessageRegExp('/The service ".*" must not be abstract\./');
        }

        $this->compileContainer($container);
    }

    public function testRepositoryFactory(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->loadContainer('orm_repository_factory');

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($definition, 'setRepositoryFactory', ['repository_factory']);
    }

    private function loadContainer(
        string $fixture,
        array $bundles = ['YamlBundle'],
        CompilerPassInterface $compilerPass = null
    ): ContainerBuilder {
        $container = $this->getContainer($bundles);
        $container->registerExtension(new DoctrineExtension());

        $this->loadFromFile($container, $fixture);

        if ($compilerPass !== null) {
            $container->addCompilerPass($compilerPass);
        }

        $this->compileContainer($container);

        return $container;
    }

    private function getContainer(array $bundles): ContainerBuilder
    {
        $map = [];
        foreach ($bundles as $bundle) {
            require_once __DIR__ . '/Fixtures/Bundles/' . $bundle . '/' . $bundle . '.php';

            $map[$bundle] = 'Fixtures\\Bundles\\' . $bundle . '\\' . $bundle;
        }

        $container = new ContainerBuilder(new ParameterBag([
            'kernel.name' => 'app',
            'kernel.debug' => false,
            'kernel.bundles' => $map,
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir' => __DIR__ . '/../../', // src dir
            'kernel.project_dir' => __DIR__ . '/../../', // src dir
            'kernel.bundles_metadata' => [],
            'container.build_id' => uniqid(),
        ]));

        // Register dummy cache services so we don't have to load the FrameworkExtension
        $container->setDefinition('cache.system', (new Definition(ArrayAdapter::class))->setPublic(true));
        $container->setDefinition('cache.app', (new Definition(ArrayAdapter::class))->setPublic(true));

        return $container;
    }

    /**
     * Assertion on the Class of a DIC Service Definition.
     */
    private function assertDICDefinitionClass(Definition $definition, string $expectedClass): void
    {
        $this->assertEquals($expectedClass, $definition->getClass(), 'Expected Class of the DIC Container Service Definition is wrong.');
    }

    private function assertDICConstructorArguments(Definition $definition, array $args): void
    {
        $this->assertEquals($args, $definition->getArguments(), "Expected and actual DIC Service constructor arguments of definition '" . $definition->getClass() . "' don't match.");
    }

    private function assertDICDefinitionMethodCallAt(
        int $pos,
        Definition $definition,
        string $methodName,
        array $params = null
    ): void {
        $calls = $definition->getMethodCalls();
        if (! isset($calls[$pos][0])) {
            $this->fail(sprintf('Method call at position %s not found!', $pos));

            return;
        }

        $this->assertEquals($methodName, $calls[$pos][0], "Method '" . $methodName . "' is expected to be called at position " . $pos . '.');

        if ($params === null) {
            return;
        }

        $this->assertEquals($params, $calls[$pos][1], "Expected parameters to methods '" . $methodName . "' do not match the actual parameters.");
    }

    /**
     * Assertion for the DI Container, check if the given definition contains a method call with the given parameters.
     */
    private function assertDICDefinitionMethodCallOnce(
        Definition $definition,
        string $methodName,
        array $params = null
    ): void {
        $calls  = $definition->getMethodCalls();
        $called = false;
        foreach ($calls as $call) {
            if ($call[0] !== $methodName) {
                continue;
            }

            if ($called) {
                $this->fail("Method '" . $methodName . "' is expected to be called only once, a second call was registered though.");
            } else {
                $called = true;
                if ($params !== null) {
                    $this->assertEquals($params, $call[1], "Expected parameters to methods '" . $methodName . "' do not match the actual parameters.");
                }
            }
        }

        if ($called) {
            return;
        }

        $this->fail("Method '" . $methodName . "' is expected to be called once, definition does not contain a call though.");
    }

    private function assertDICDefinitionMethodCallCount(
        Definition $definition,
        string $methodName,
        array $params = [],
        int $nbCalls = 1
    ): void {
        $calls  = $definition->getMethodCalls();
        $called = 0;
        foreach ($calls as $call) {
            if ($call[0] !== $methodName) {
                continue;
            }

            if ($called > $nbCalls) {
                break;
            }

            if (isset($params[$called])) {
                $this->assertEquals($params[$called], $call[1], "Expected parameters to methods '" . $methodName . "' do not match the actual parameters.");
            }

            $called++;
        }

        $this->assertEquals($nbCalls, $called, sprintf('The method "%s" should be called %d times', $methodName, $nbCalls));
    }

    /**
     * Assertion for the DI Container, check if the given definition does not contain a method call with the given parameters.
     */
    private function assertDICDefinitionNoMethodCall(
        Definition $definition,
        string $methodName,
        array $params = null
    ): void {
        $calls = $definition->getMethodCalls();
        foreach ($calls as $call) {
            if ($call[0] !== $methodName) {
                continue;
            }

            if ($params !== null) {
                $this->assertNotEquals($params, $call[1], "Method '" . $methodName . "' is not expected to be called with the given parameters.");
            } else {
                $this->fail("Method '" . $methodName . "' is not expected to be called");
            }
        }
    }

    private function compileContainer(ContainerBuilder $container): void
    {
        $container->getCompilerPassConfig()->setOptimizationPasses([new ResolveChildDefinitionsPass()]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();
    }
}

class DummySchemaAssetsFilter
{
    /** @var string */
    private $tableToIgnore;

    public function __construct(string $tableToIgnore)
    {
        $this->tableToIgnore = $tableToIgnore;
    }

    public function __invoke($assetName): bool
    {
        if ($assetName instanceof AbstractAsset) {
            $assetName = $assetName->getName();
        }

        return $assetName !== $this->tableToIgnore;
    }
}
