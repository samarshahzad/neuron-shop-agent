<?php

namespace App\Command;

use App\Database\ShopDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:setup',
    description: 'Creates the shop and chat tables and fills them with demo data.',
)]
final class SetupDatabaseCommand extends Command
{
    public function __construct(private readonly ShopDatabase $database)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Drop existing tables first (deletes all data!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            $this->database->drop();
            $io->note('Existing tables dropped.');
        } elseif ($this->database->isInstalled()) {
            $io->warning('The tables already exist. Run with --reset to recreate them (this deletes all data).');

            return Command::SUCCESS;
        }

        $this->database->create();
        $counts = $this->database->seed();

        $io->success(sprintf(
            'Database ready: %d customers, %d products, %d orders.',
            $counts['customers'],
            $counts['products'],
            $counts['orders'],
        ));

        return Command::SUCCESS;
    }
}
