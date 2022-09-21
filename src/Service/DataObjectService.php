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

    public function importFromFixture(): void
    {
        /** @var PopulateFactory $factory */
        $factory = Injector::inst()->create(PopulateFactory::class);

        foreach (self::config()->get('fixture_files') as $fixtureFile) {
            DB::alteration_message(sprintf('Processing %s', $fixtureFile), 'created');
            $fixture = new YamlFixture($fixtureFile);
            $fixture->writeInto($factory);

            $fixture = null;
        }

        $factory->processFailedFixtures();
    }

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
