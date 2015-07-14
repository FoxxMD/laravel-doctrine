<?php namespace Mitch\LaravelDoctrine\Console\Migrations;

use Mitch\LaravelDoctrine\Console\Helper\CommandHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Doctrine\DBAL\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;


class MigrateCommand extends DoctrineMigrateCommand {

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('doctrine:migrations:migrate')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'The database connection to use for this command.')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command.')
            ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // EM and DB options cannot be set at the same time
        if (null !== $input->getOption('em') && null !== $input->getOption('db')) {
            throw new \InvalidArgumentException('Cannot set both "em" and "db" for command execution.');
        }

        CommandHelper::setApplicationHelper($this->getApplication(), $input);
        CommandHelper::setMigrationConfiguration($this->getApplication(), $this);

        parent::execute($input, $output);
    }

}
