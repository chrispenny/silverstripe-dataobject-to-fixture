<?php

namespace ChrisPenny\DataObjectToFixture\Service;

use DNADesign\Populate\PopulateFactory;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DB;

class DataObjectService
{

    use Configurable;
    use Injectable;

    private static array $fixture_files = [];

    /**
     * Creates DataObjects using Populate functionality from fixture files defined in site configuration yml files
     * This method uses similar functionality to importFromStream().
     * importFromStream() could be called within the foreach loop however this would be less efficient as the
     * processFailedFixtures() function would be called after each fixture file rather than after processing all files
     *
     * @return void
     */
    public function importFromFixture(): void
    {
        /** @var PopulateFactory $factory */
        $factory = Injector::inst()->create(PopulateFactory::class);

        foreach (self::config()->get('fixture_files') as $fixtureFile) {
            DB::alteration_message(sprintf('Processing %s', $fixtureFile), 'created');
            $fixture = new YamlFixture($fixtureFile);
            $fixture->writeInto($factory);

            unset($fixture);
        }

        $factory->processFailedFixtures();
    }

    /**
     * Takes the provided stream and processes the fixture->DataObject functionality using Populate methods
     *
     * @param string $stream File path of yml fixture file OR string containing yml content
     * @return void
     */
    public function importFromStream(string $stream): void
    {
        /** @var PopulateFactory $factory */
        $factory = Injector::inst()->create(PopulateFactory::class);

        DB::alteration_message('Processing provided Stream', 'created');
        $fixture = new YamlFixture($stream);
        $fixture->writeInto($factory);

        unset($fixture);

        $factory->processFailedFixtures();
    }

}
