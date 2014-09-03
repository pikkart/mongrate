<?php

namespace Mongrate\Command;

use Doctrine\MongoDB\Configuration;
use Doctrine\MongoDB\Connection;
use Mongrate\Exception\MigrationDoesntExist;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class TestMigrationCommand extends BaseCommand
{
    private $db;

    private $output;

    protected function configure()
    {
        $this->setName('test')
            ->setDescription('Test a migration up and down.')
            ->addArgument('name', InputArgument::REQUIRED, 'The class name, formatted like "UpdateAddressStructure_20140523".')
            ->addArgument('upOrDown', InputArgument::OPTIONAL, 'Whether to test going up or down. If left blank, both are tested.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $className = $input->getArgument('name');
        $upOrDown = $input->getArgument('upOrDown');

        $classFile = $this->getMigrationClassFileFromClassName($className);
        if (file_exists($classFile)) {
            require_once $classFile;
        } else {
            throw new MigrationDoesntExist($className, $classFile);
        }

        $config = new Configuration();
        $conn = new Connection($this->params['mongodb_server'], [], $config);
        $this->db = $conn->selectDatabase('mongrate_test_' . $className);

        if ($upOrDown === 'up' || $upOrDown === 'down') {
            $this->test($upOrDown, $className);
        } else {
            $this->test('up', $className);
            $this->test('down', $className);
        }
    }

    private function test($upOrDown, $className)
    {
        $testsDirectory = $this->params['migrations_directory'] . '/' . $className . '/';
        $inputFile = $testsDirectory . $upOrDown . '-input.yml';
        $verifierFile = $testsDirectory . $upOrDown . '-verifier.yml';

        $this->addFixturesToDatabaseFromYamlFile($inputFile);
        $this->applyMigration($className, $upOrDown);
        $this->verifyDatabaseAgainstYamlFile($verifierFile);
    }

    private function addFixturesToDatabaseFromYamlFile($fixturesFile)
    {
        $yaml = new Parser();
        $fixtures = $yaml->parse(file_get_contents($fixturesFile));

        foreach ($fixtures as $collectionName => $collectionFixtures) {
            $collection = $this->db->selectCollection($collectionName);

            // Start off with an empty collection by removing all rows with an empty query.
            $collection->remove([]);

            foreach ($collectionFixtures as $i => $collectionFixture) {
                $collectionFixture['_orderInTestYamlFile'] = $i;
                $collection->insert($collectionFixture);
            }
        }
    }

    private function applyMigration($className, $upOrDown)
    {
        $fullClassName = 'Mongrate\Migrations\\' . $className;
        $migration = new $fullClassName();

        if ($upOrDown === 'up') {
            $this->output->writeln('<info>Testing ' . $className . ' going up.</info>');
            $migration->up($this->db);
        } elseif ($upOrDown === 'down') {
            $this->output->writeln('<info>Testing ' . $className . ' going down.</info>');
            $migration->down($this->db);
        } else {
            throw new \InvalidArgumentException('upOrDown does not support this value: ' . $upOrDown);
        }
    }

    private function verifyDatabaseAgainstYamlFile($verifierFile)
    {
        $yaml = new Parser();
        $verifier = $yaml->parse(file_get_contents($verifierFile));

        foreach ($verifier as $collectionName => $verifierObjects) {
            $collection = $this->db->selectCollection($collectionName);
            $verifierObjects = $this->normalizeObject($verifierObjects);
            $verifierObjectsJson = json_encode($verifierObjects);

            $actualObjects = array_values($collection->find(
                ['$query' => [], '$orderby' => ['_orderInTestYamlFile' => 1]],
                ['_id' => 0, '_orderInTestYamlFile' => 0]
            )->toArray());
            $actualObjects = $this->normalizeObject($actualObjects);
            $actualObjectsJson = json_encode($actualObjects);

            $isVerified = $actualObjectsJson === $verifierObjectsJson;
            if ($isVerified) {
                $this->output->writeln('<info>Test passed.</info>');
            } else {
                $this->output->writeln('<error>Test failed.</error>');
                $this->output->writeln('<comment>Expected:</comment>');
                $this->output->writeln($verifierObjectsJson);
                $this->output->writeln('<comment>Actual:</comment>');
                $this->output->writeln($actualObjectsJson);
            }
        }
    }

    private function normalizeObject($object)
    {
        if (is_string($object) || is_int($object) || is_bool($object) || is_float($object)) {
            return $object;
        } elseif (is_array($object)) {
            // If the array uses numeric keys, keep the keys intact.
            // If the array uses string keys, sort them alphabetically.
            if (array_keys($object)[0] !== 0) {
                ksort($object);
            }

            foreach ($object as $key => &$value) {
                $value = $this->normalizeObject($value);
            }

            return $object;
        } else {
            throw new \InvalidArgumentException('Unexpected object type: ' . var_dump($object, true));
        }
    }
}