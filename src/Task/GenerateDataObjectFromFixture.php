<?php

namespace ChrisPenny\DataObjectToFixture\Task;

use ChrisPenny\DataObjectToFixture\Service\DataObjectService;
use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
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

    private ?array $configuredFixtureFiles = null;

    public function __construct()
    {
        parent::__construct();

        $service = Injector::inst()->create(DataObjectService::class);
        $this->configuredFixtureFiles = $service::config()->get('fixture_files');
    }

    /**
     * @param HTTPRequest|mixed $request
     * @throws Exception
     */
    public function run($request): void // phpcs:ignore
    {
        $service = DataObjectService::create();

        try {
            $this->previousExecution = ini_get('max_execution_time');
            ini_set('max_execution_time', 60);

            if ($request->getVar('from-configuration')) {
                $service->importFromFixture();
            } elseif ($request->postVar('fixture-details')) {
                $service->importFromStream($request->postVar('fixture-details'));
            } else {
                $this->outputInitialForm();
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            ini_set('max_execution_time', $this->previousExecution);
        }
    }

    protected function outputInitialForm(): void
    {
        if ($this->configuredFixtureFiles) {
            echo 'Fixture files have been defined in your site configuration click ' .
            '<a href="?from-configuration=1">here</a> to generate DataObjects from the configured file(s)';
        }

        echo '<form action="" method="post">';
        echo '<p><strong>Fixture data to us to create DataObject(s):</strong></p>';
        echo'<div><textarea cols="90" rows="50" name="fixture-details"></textarea></div>';

        echo '<button type="submit">Submit</button>';

        echo '</form>';
    }

}
