<?php  namespace Mitch\LaravelDoctrine\Console\Helper;

use Mitch\LaravelDoctrine\IlluminateRegistry;
use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Doctrine\DBAL\Migrations\Tools\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application;
use Doctrine\DBAL\Migrations\Configuration\Configuration;

class CommandHelper 
{
    public static function setApplicationHelper(Application $application, InputInterface $input)
    {
        $laravel = $application->getLaravel();
        $registry = $laravel[IlluminateRegistry::class];
        $managerNames = $registry->getManagerNames();

        if (empty($managerNames)) {
            self::setApplicationConnection($application, $input->getOption('db'));
        } else {
            self::setApplicationEntityManager($application, $input->getOption('em'));
        }
    }

    public static function setApplicationConnection(Application $application, $connName)
    {
        $laravel = $application->getLaravel();
        $connection = $laravel[IlluminateRegistry::class]->getConnection($connName);

        $helperSet = $application->getHelperSet();
        $helperSet->set(new ConnectionHelper($connection), 'db');
    }

    public static function setApplicationEntityManager(Application $application, $emName)
    {
        $laravel = $application->getLaravel();
        $em = $laravel[IlluminateRegistry::class]->getManager($emName);
         
        $helperSet = $application->getHelperSet();
        $helperSet->set(new EntityManagerHelper($em), 'em');
        $helperSet->set(new ConnectionHelper($em->getConnection()), 'db');
    }

    public static function setMigrationConfiguration(Application $application, AbstractCommand $command)
    {
        $laravel = $application->getLaravel();
        $config = $laravel['config']->get('doctrine.migrations');
        $directory = array_get($config, 'directory');
        if ( ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $configuration = new Configuration($application->getHelperSet()->get('connection')->getConnection());
        $configuration->setMigrationsDirectory(array_get($config, 'directory'));
        $configuration->setMigrationsNamespace(array_get($config, 'namespace'));
        $configuration->setMigrationsTableName(array_get($config, 'table', 'doctrine_migration_versions'));
        $configuration->registerMigrationsFromDirectory(array_get($config, 'directory'));

        $command->setMigrationConfiguration($configuration);
    }
}