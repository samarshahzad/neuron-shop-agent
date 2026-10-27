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
    description: 'Creates the shop, chat and embeddings tables and fills them with demo data.',
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
        } elseif ($this->database->isInstalled() && $this->database->isCurrent()) {
            $io->writeln('Database is up to date. Run with --reset to recreate the demo data.');

            return Command::SUCCESS;
        } elseif ($this->database->isInstalled()) {
            // Created by an older version of this demo app: rebuild it with the new catalogue.
            $io->note('The demo database is from an older version (no product descriptions or embeddings). Recreating it with the new catalogue; demo data and chat history are reset.');
            $this->database->drop();
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
