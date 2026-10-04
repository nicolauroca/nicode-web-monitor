<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Command;

use Joomla\Console\Command\AbstractCommand;
use Nicode\Plugin\Console\NicodeWebMonitor\Inventory\Collector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InventoryCommand extends AbstractCommand
{
    protected static $defaultName = 'nicode:inventory';

    public function __construct(private Collector $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Read local Joomla inventory as JSON (development version).');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $inventory = $this->collector->collect();
        $output->writeln(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return $inventory['status'] === 'complete' ? 0 : 2;
    }
}
