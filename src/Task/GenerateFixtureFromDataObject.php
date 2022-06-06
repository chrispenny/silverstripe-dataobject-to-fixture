<?php

namespace ChrisPenny\DataObjectToFixture\Task;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * @codeCoverageIgnore
 */
class GenerateFixtureFromDataObject extends BuildTask
{

    protected $title = 'Generate Fixture From DataObject'; // phpcs:ignore

    private static $segment = 'generate-fixture-from-dataobject'; // phpcs:ignore

    protected $description = 'Generate a text fixture from a DataObject in your Database'; // phpcs:ignore

    /**
     * @param HTTPRequest|mixed $request
     * @throws Exception
     */
    public function run($request): void
    {
        $className = $request->getVar('ClassName');
        $id = $request->getVar('ID');

        if ($className && $id) {
            $this->outputFixture($request, $className, (int) $id);

            return;
        }

        if ($className) {
            $this->outputClassForm($className);

            return;
        }

        $this->outputInitialForm();
    }

    protected function outputInitialForm(): void
    {
        $classes = ClassInfo::subclassesFor(DataObject::class);
        sort($classes);

        echo '<form action="" method="get">';

        echo '<p>';
        echo '<label>ClassName (required): ';
        echo '<select name="ClassName" required>';
        echo '<option value="">-- Select --</option>';

        foreach ($classes as $class) {
            echo sprintf('<option value="%s">%s</option>', $class, $class);
        }

        echo '</select>';
        echo '</label>';
        echo '</p>';

        echo '<p>';
        echo '<label>ID (optional): ';
        echo '<input name="ID" type="text" value="" />';
        echo '</label>';
        echo '<br>';
        echo 'If you know the ID, then chuck it in here. If you don\'t know the ID, then submit the ClassName, and you';
        echo ' will be provided with an interface to select the record you wish to generate a fixture for.';
        echo '</p>';

        echo '<button type="submit">Submit</button>';

        echo '</form>';
    }

    protected function outputClassForm(string $className): void
    {
        $dbFields = Config::inst()->get($className, 'db');
        /** @var DataList|DataObject[] $dataObjects */
        $dataObjects = $className::get();

        if (!$dataObjects) {
            echo sprintf('<p>Failed to retrieve DataObjects. ClassName: %s</p>', $className);

            return;
        }

        if ($dataObjects->count() === 0) {
            echo sprintf('<p>No Database records found for ClassName: %s</p>', $className);

            return;
        }

        echo '<p><a href="/dev/tasks/generate-fixture-from-dataobject">< Back to the beginning</a></p>';

        echo '<p>Please select the record you wish to generate the fixture for below</p>';

        // Remove Datatime and Boolean fields, as they're (likely) not that useful for trying to find a specific record
        $dbFields = array_diff($dbFields, ['Datetime', 'Boolean']);
        $linkTemplate = '<td><a href="/dev/tasks/generate-fixture-from-dataobject?ClassName=%s&ID=%s">Link</a></td>';

        echo '<table cellpadding="2px" style="display: block; width: 100%; overflow: scroll">';

        echo '<thead>';
        echo '<tr>';
        echo '<th>Generate</th>';
        echo '<th>ID</th>';

        foreach (array_keys($dbFields) as $fieldName) {
            echo sprintf('<th>%s</th>', $fieldName);
        }

        echo '</tr>';
        echo '</thead>';

        echo '<tbody>';

        foreach ($dataObjects as $dataObject) {
            echo '<tr>';
            echo sprintf($linkTemplate, $className, $dataObject->ID);
            echo sprintf('<td>%s</td>', $dataObject->ID);

            foreach (array_keys($dbFields) as $fieldName) {
                echo sprintf('<td>%s</td>', $dataObject->relField($fieldName));
            }

            echo '</tr>';
        }

        echo '</tbody>';

        echo '</table>';
    }

    /**
     * @param HTTPRequest $request
     * @param string $className
     * @param int $id
     * @throws Exception
     */
    protected function outputFixture(HTTPRequest $request, string $className, int $id): void
    {
        if (!$className) {
            echo '<p>No ClassName provided</p>';

            return;
        }

        if (!$id) {
            echo '<p>No ID provided</p>';

            return;
        }

        $maxDepth = (int) $request->getVar('maxDepth');

        /** @var DataObject $dataObject */
        $dataObject = $className::get()->byID($id);

        if ($dataObject === null) {
            echo sprintf('<p>DataObject not found. ClassName: %s, ID: %s</p>', $className, $id);

            return;
        }

        // Check isInDB() rather than exists(), as exists() has additional checks for (eg) Files
        if (!$dataObject->isInDB()) {
            echo sprintf('<p>DataObject failed "isInDB()" requirement. ClassName: %s, ID: %s</p>', $className, $id);

            return;
        }

        echo sprintf(
            '<p><a href="/dev/tasks/generate-fixture-from-dataobject?ClassName=%s">< Back to list of records</a></p>',
            $className
        );

        echo '<form action="" method="get">';
        echo sprintf('<input type="hidden" name="ClassName" value="%s" />', $className);
        echo sprintf('<input type="hidden" name="ID" value="%s" />', $id);
        echo '<label>Max allowed depth (optional): ';
        echo sprintf('<input name="maxDepth" type="text" value="%s" /><br />', $maxDepth ?: '');
        echo 'This can be useful if you are hitting "Maximum function nesting level" errors<br />';
        echo '</label>';
        echo '<button type="submit">Submit</button>';
        echo '</form>';

        $service = FixtureService::create();

        if ($maxDepth) {
            $service->setAllowedDepth($maxDepth);
        }

        $service->addDataObject($dataObject);

        echo '<p>Warnings:</p>';
        echo '<p>';
        echo implode('<br>', $service->getWarnings());
        echo '</p>';

        echo '<p>Fixture output:</p>';
        echo sprintf('<textarea cols="90" rows="50">%s</textarea>', $service->outputFixture());
    }

}
