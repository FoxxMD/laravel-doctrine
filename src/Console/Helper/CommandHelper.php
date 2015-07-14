<?php  namespace Mitch\LaravelDoctrine\Console\Helper;

use Mitch\LaravelDoctrine\IlluminateRegistry;
use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Doctrine\DBAL\Migrations\Tools\Console\Command\AbstractCommand;

class CommandHelper 
{
    public function setApplicationHelpers(Application $application, InputInterface $input)
    {
        $laravel = $application->getLaravel();
        $registry = $laravel[IlluminateRegistry::class];
        $entityManager = $registry->getManager($input->getOption('em'));
        
        $helperSet = $application->getHelperSet();
        $helperSet->set(new ConnectionHelper($entityManager->getConnection()), 'db');
        $helperSet->set(new EntityManagerHelper($entityManager), 'em');
    }

    public function setMigrationConfiguration(Application $application, AbstractCommand $command)
    {
        $laravel = $application->getLaravel();
        $config = $laravel['config']('doctrine.migrations');
        $directory = array_get($config, 'directory');
        if ( ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $configuration = new Configuration($this->getHelper('connection')->getConnection());
        $configuration->setMigrationsDirectory(array_get($config, 'directory'));
        $configuration->setMigrationsNamespace(array_get($config, 'namespace'));
        $configuration->setMigrationsTableName(array_get($config, 'table', 'doctrine_migration_versions'));
        $configuration->registerMigrationsFromDirectory(array_get($config, 'directory'));

        $command->setMigrationConfiguration($configuration);
    }
}