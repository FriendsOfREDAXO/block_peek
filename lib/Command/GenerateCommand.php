<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Command;

use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore;
use FriendsOfRedaxo\BlockPeek\Picker\Providers;
use rex_addon;
use rex_console_command;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setName('block_peek:generate')
            ->setDescription('Generate module picker previews')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Generate one module ID')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate unchanged previews');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        if (rex_addon::get('block_peek')->getConfig('picker') !== '|1|') {
            $io->note('Module picker is disabled.');
            return 0;
        }
        $module = $input->getOption('module');
        if ($module !== null && (filter_var($module, FILTER_VALIDATE_INT) === false || (int) $module < 1)) {
            $io->error('--module must be a positive integer.');
            return 1;
        }
        // The source file survives cache:clear, with nothing to create or ignore.
        $lock = fopen(__FILE__, 'r');
        if ($lock === false) {
            throw new RuntimeException('Unable to open generation lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $io->note('Generation is already running.');
            return 0;
        }
        try {
            if ($module !== null && !in_array((int) $module, array_column(PreviewGenerator::modules(), 'id'), true)) {
                $io->error('Module ' . $module . ' does not exist.');
                return 1;
            }
            $provider = Providers::fromConfig();
            if ($provider === null) {
                $io->error('No provider available for generation.');
                return 1;
            }
            $counts = (new PreviewGenerator($provider, new PreviewStore()))->run($module === null ? null : (int) $module, (bool) $input->getOption('force'), static function (string $line) use ($io): void {
                $io->writeln($line);
            });
            $io->success(sprintf('Generated: %d, skipped: %d, failed: %d', $counts['generated'], $counts['skipped'], $counts['failed']));
            return 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
