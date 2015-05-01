<?php namespace Mitch\LaravelDoctrine;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Common\EventManager;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\ServiceProvider;
use Mitch\LaravelDoctrine\Cache;
use Mitch\LaravelDoctrine\Configuration\DriverMapper;
use Mitch\LaravelDoctrine\Configuration\SqlMapper;
use Mitch\LaravelDoctrine\Configuration\SqliteMapper;
use Mitch\LaravelDoctrine\Configuration\OCIMapper;
use Mitch\LaravelDoctrine\EventListeners\SoftDeletableListener;
use Mitch\LaravelDoctrine\EventListeners\TablePrefix;
use Mitch\LaravelDoctrine\Filters\TrashedFilter;
use Mitch\LaravelDoctrine\Validation\DoctrinePresenceVerifier;

class LaravelDoctrineServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     * @var bool
     */
    protected $defer = false;

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/doctrine.php' => config_path('doctrine.php')
        ], 'config');

        $this->extendAuthManager();
    }

    /**
     * Register the service provider.
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/doctrine.php', 'doctrine'
        );

        $this->registerConfigurationMapper();
        $this->registerCacheManager();
        $this->registerManagerRegistry();
        $this->registerEntityManager();
        $this->registerClassMetadataFactory();
        $this->registerValidationVerifier();

        $this->commands([
            'Mitch\LaravelDoctrine\Console\GenerateProxiesCommand',
            'Mitch\LaravelDoctrine\Console\SchemaCreateCommand',
            'Mitch\LaravelDoctrine\Console\SchemaUpdateCommand',
            'Mitch\LaravelDoctrine\Console\SchemaDropCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearCollectionRegionCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearMetadataCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearEntityRegionCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearQueryCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearQueryRegionCommand',
            'Mitch\LaravelDoctrine\Console\CacheClearResultCommand'
            'Mitch\LaravelDoctrine\Console\LoadDataFixturesCommand'
        ]);
    }

    /**
     * The driver mapper's instance needs to be accessible from anywhere in the application,
     * for registering new mapping configurations or other storage libraries.
     */
    private function registerConfigurationMapper()
    {
        $this->app->bind(DriverMapper::class, function () {
            $mapper = new DriverMapper;
            $mapper->registerMapper(new SqlMapper);
            $mapper->registerMapper(new SqliteMapper);
            $mapper->registerMapper(new OCIMapper);
            return $mapper;
        });
    }

    /**
     * Registers a new presence verifier for Laravel 4 validation. Specifically, this
     * is for the use of the Doctrine ORM.
     */
    public function registerValidationVerifier()
    {
        $this->app->bind('validation.presence', function() {
            return new DoctrinePresenceVerifier($this->app[EntityManagerInterface::class]);
        }, true);
    }

    public function registerCacheManager()
    {
        $this->app->bind(CacheManager::class, function ($app) {
            $manager = new CacheManager(config('doctrine.cache'));
            $manager->add(new Cache\ApcProvider);
            $manager->add(new Cache\MemcacheProvider);
            $manager->add(new Cache\RedisProvider);
            $manager->add(new Cache\XcacheProvider);
            $manager->add(new Cache\NullProvider);
            return $manager;
        });
    }

    private function createMetadataConfiguration(
        array $paths,
        $isDevMode = false,
        $proxyDir = null,
        \Doctrine\Common\Cache\Cache $cache = null,
        $useSimpleAnnotationReader = true,
        $autoGenerateProxyClasses = false,
        $proxyNamespace = null,
        $repository = 'Doctrine\ORM\EntityRepository',
        $logger = null
    ) {
        $metadata = Setup::createAnnotationMetadataConfiguration(
            $paths,
            $isDevMode,
            $proxyDir,
            $cache,
            $useSimpleAnnotationReader
        );

        $metadata->addFilter('trashed', TrashedFilter::class);
        $metadata->setAutoGenerateProxyClasses($autoGenerateProxyClasses);
        if ($proxyNamespace) {
            $metadata->setProxyNamespace($config['proxy']['namespace']);
        }
        $metadata->setDefaultRepositoryClassName($repository);
        $metadata->setSQLLogger($logger);

        return $metadata;
    }

    private function mapEntityManagers($config, $defaultDatabase)
    {
        if (!isset($config['default_connection'])) {
            $config['default_connection'] = $defaultDatabase;
        }

        if (!isset($config['entity_managers'])) {
            $config['entity_managers'] = [
                $defaultDatabase => [
                    'metadata' => $config['metadata']
                ]
            ];
        }

        return $config;
    }

    private function createManagerInstances($config, $databaseConnections, $debug, CacheManager $cacheManager)
    {
        $registryConnections = [];
        $registryManagers = [];

        $proxyNamespace = isset($config['proxy']['namespace']) ? $config['proxy']['namespace'] : null;

        $eventManager = new EventManager;
        $eventManager->addEventListener(Events::onFlush, new SoftDeletableListener);

        foreach ($config['entity_managers'] as $name => $managerConfig) {
            $connectionName = isset($managerConfig['connection']) ? $managerConfig['connection'] : $name;

            // skip connection names not defined in Laravel's database configuration
            if (!isset($databaseConnections[$connectionName])) {
                continue;
            }

            $databaseConfig = $databaseConnections[$connectionName];
            $cacheProvider = isset($managerConfig['cache_provider']) ? $managerConfig['cache_provider'] : $config['cache_provider'];
            $repository = isset($managerConfig['repository']) ? $managerConfig['repository'] : $config['repository'];
            $simpleAnnotations = isset($managerConfig['simple_annotations']) ? $managerConfig['simple_annotations'] : $config['simple_annotations'];
            $logger = isset($managerConfig['logger']) ? $managerConfig['logger'] : $config['logger'];

            $metadata = $this->createMetadataConfiguration(
                $managerConfig['metadata'],
                $debug,
                $config['proxy']['directory'],
                $cacheManager->getCache($cacheProvider),
                $simpleAnnotations,
                $config['proxy']['auto_generate'],
                $proxyNamespace,
                $repository,
                $logger
            );

            $connection = DriverManager::getConnection(
                $this->mapLaravelToDoctrineConfig($databaseConfig),
                $metadata,
                $eventManager
            );

            $registryConnections[$connectionName] = "doctrine.dbal.{$connectionName}_connection";

            $entityManager = EntityManager::create($connection, $metadata, $eventManager);
            $entityManager->getFilters()->enable('trashed');
            $registryManagers[$name] = "doctrine.orm.{$name}_entity_manager";

            $this->app->instance($registryConnections[$connectionName], $connection);
            $this->app->instance($registryManagers[$name], $entityManager);

            if ($connectionName === $config['default_connection']) {
                $registryConnections['default'] = 'doctrine.dbal.default_connection';
                $registryManagers['default'] = 'doctrine.orm.default_entity_manager';

                $this->app->instance('doctrine.dbal.default_connection', $connection);
                $this->app->instance('doctrine.orm.default_entity_manager', $entityManager);
            }

        }

        return [$registryConnections, $registryManagers];
    }

    private function registerManagerRegistry()
    {
        $this->app->singleton(IlluminateRegistry::class, function ($app) {
            $config = config('doctrine');
            $databaseConnections = config('database')['connections'];
            $defaultDatabase = config('database')['default'];

            $config = $this->mapEntityManagers($config, $defaultDatabase);

            list($registryConnections, $registryManagers) = $this->createManagerInstances(
                $config,
                $databaseConnections,
                $app['config']['app.debug'],
                $app[CacheManager::class]
            );

            return new IlluminateRegistry(
                $app,
                $registryConnections,
                $registryManagers
            );
        });
        $this->app->alias(IlluminateRegistry::class, ManagerRegistry::class);
    }

    private function registerEntityManager()
    {
        $this->app->singleton(EntityManager::class, function ($app) {
            return $app->make(IlluminateRegistry::class)->getManager();
        });
        $this->app->alias(EntityManager::class, EntityManagerInterface::class);
    }

    private function registerClassMetadataFactory()
    {
        $this->app->singleton(ClassMetadataFactory::class, function ($app) {
            return $app[EntityManager::class]->getMetadataFactory();
        });
    }

    private function extendAuthManager()
    {
        $this->app[AuthManager::class]->extend('doctrine', function ($app) {
            return new DoctrineUserProvider(
                $app['Illuminate\Contracts\Hashing\Hasher'],
                $app[IlluminateRegistry::class],
                config('auth.model')
            );
        });
    }

    /**
     * Get the services provided by the provider.
     * @return array
     */
    public function provides()
    {
        return [
            CacheManager::class,
            EntityManagerInterface::class,
            EntityManager::class,
            ClassMetadataFactory::class,
            DriverMapper::class,
            AuthManager::class,
        ];
    }

    /**
     * Map Laravel's to Doctrine's database configuration requirements.
     * @param $databaseConfig
     * @throws \Exception
     * @return array
     */
    private function mapLaravelToDoctrineConfig($databaseConfig)
    {
        return $this->app->make(DriverMapper::class)->map($databaseConfig);
    }
}
