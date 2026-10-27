<?php

namespace App\Command;

use App\Recommendation\EmbeddingsProviderFactory;
use App\Recommendation\ProductEmbeddingIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:products:embed',
    description: 'Creates AI embedding vectors for all products (only for new or changed products).',
)]
final class EmbedProductsCommand extends Command
{
    public function __construct(
        private readonly ProductEmbeddingIndex $index,
        private readonly EmbeddingsProviderFactory $embeddings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Recalculate all vectors, even unchanged ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->embeddings->isEnabled()) {
            $io->note('EMBEDDINGS_PROVIDER=none: AI similarity is off, recommendations use rules only.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Embedding products with <info>%s</info> ...', $this->embeddings->modelId()));

        try {
            $result = $this->index->build(
                $this->embeddings->create(),
                $this->embeddings->modelId(),
                (bool) $input->getOption('force'),
                static function (string $sku, string $status) use ($io): void {
                    if ($io->isVerbose()) {
                        $io->writeln(sprintf('  %s: %s', $sku, $status));
                    }
                },
            );
        } catch (Throwable $e) {
            $io->warning([
                'Could not create embeddings: '.$e->getMessage(),
                'Recommendations still work, using rules (same category, color, material).',
                'For AI similarity with Ollama, run on your computer:  ollama pull nomic-embed-text',
                'Then run:  php bin/console app:products:embed',
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d products embedded, %d unchanged (%d total). Recommendations now use AI similarity.',
            $result['embedded'],
            $result['unchanged'],
            $result['total'],
        ));

        return Command::SUCCESS;
    }
}
