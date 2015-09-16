<?php

namespace Mongrate\Command;

use Mongrate\Migration\Name;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure()
    {
        // Name cannot be 'list' because that's the name of the Symfony command run if no
        // name is specified.
        $this->setName('list-migrations')
            ->setDescription('List available migrations.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->service->ensureMigrationsDirectoryExists();

        $iterator = new \DirectoryIterator($this->configuration->getMigrationsDirectory());
        $migrations = [];

        foreach ($iterator as $file) {
            $file = (string) $file;
            if ($file === '.'|| $file === '..') {
                continue;
            }

            $name = new Name($file);

            $migrations[] = [
                'name' => $name,
                'isApplied' => $this->service->isMigrationApplied($name),
            ];
        }

        // Sort the migrations alphabetically so the list is easier to scan.
        usort($migrations, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        foreach ($migrations as $migration) {
            if ($migration['isApplied']) {
                $output->writeln(sprintf('<comment>%s</comment> <info>applied</info>', $migration['name']));
            } else {
                $output->writeln(sprintf('<comment>%s</comment> <error>not applied</error>', $migration['name']));
            }
        }
    }
}
