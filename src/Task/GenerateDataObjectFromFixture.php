<?php

namespace ChrisPenny\DataObjectToFixture\Task;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use DNADesign\Populate\PopulateFactory;
use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DB;
use Throwable;

/**
 * @codeCoverageIgnore
 */
class GenerateDataObjectFromFixture extends BuildTask
{

    protected $title = 'Generate DataObject From Fixture'; // phpcs:ignore

    protected $description = 'Generate a DataObject from a fixture file'; // phpcs:ignore

    private static $segment = 'generate-dataobject-from-fixture'; // phpcs:ignore

    private ?int $previousExecution = null;

    /**
     * @param HTTPRequest|mixed $request
     * @throws Exception
     */
    public function run($request): void // phpcs:ignore
    {
        try {
            $this->previousExecution = ini_get('max_execution_time');
            ini_set('max_execution_time', 60);

            $this->importFromFixture();
        } catch (Throwable $e) {
            throw $e;
        } finally {
            ini_set('max_execution_time', $this->previousExecution);
        }
    }

    protected function importFromFixture(): void
    {
        /** @var PopulateFactory $factory */
        $factory = Injector::inst()->create(PopulateFactory::class);
        $service = Injector::inst()->create(FixtureService::class);

        foreach ($service::config()->get('fixture_files') as $fixtureFile) {
            DB::alteration_message(sprintf('Processing %s', $fixtureFile), 'created');
            $fixture = new YamlFixture($fixtureFile);
            $fixture->writeInto($factory);

            $fixture = null;
        }

        $factory->processFailedFixtures();
    }

}
