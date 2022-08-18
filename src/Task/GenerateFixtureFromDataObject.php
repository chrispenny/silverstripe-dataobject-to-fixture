<?php

namespace ChrisPenny\DataObjectToFixture\Task;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\PaginatedList;
use Throwable;

/**
 * @codeCoverageIgnore
 */
class GenerateFixtureFromDataObject extends BuildTask
{

    protected $title = 'Generate Fixture From DataObject'; // phpcs:ignore

    protected $description = 'Generate a text fixture from a DataObject in your Database'; // phpcs:ignore

    private static $segment = 'generate-fixture-from-dataobject'; // phpcs:ignore

    private ?int $previousExecution = null;

    /**
     * @param HTTPRequest|mixed $request
     * @throws Exception
     */
    public function run($request): void
    {
        try {
            $this->previousExecution = ini_get('max_execution_time');
            ini_set('max_execution_time', 60);

            $this->outputStyles();

            $className = $request->getVar('ClassName');
            $id = $request->getVar('ID');

            // We have both a ClassName and ID, which means we can render out the fixture for a particular record
            if ($className && $id) {
                $this->outputFixture($request, $className, (int)$id);

                return;
            }

            // We have a ClassName, but not ID yet, this means the user needs to be provided with a list of available
            // records for that particular class
            if ($className) {
                $this->outputClassForm($request, $className);

                return;
            }

            // The initial form is a list of available classes
            $this->outputInitialForm();
        } catch (Throwable $e) {
            throw $e;
        } finally {
            ini_set('max_execution_time', $this->previousExecution);
        }
    }

    protected function outputInitialForm(): void
    {
        $classes = ClassInfo::subclassesFor(DataObject::class);
        sort($classes);

        echo '<form action="" method="get">';

        echo '<p>';
        echo '<label>ClassName (required):<br />';
        echo '<select name="ClassName" required>';
        echo '<option value="">-- Select --</option>';

        foreach ($classes as $class) {
            echo sprintf('<option value="%s">%s</option>', $class, $class);
        }

        echo '</select>';
        echo '</label>';
        echo '</p>';

        echo '<p>';
        echo '<label>ID (optional):<br />';
        echo '<input name="ID" type="number" value="" />';
        echo '</label>';
        echo '<br>';
        echo '<span class="note">If you know the ID, then chuck it in here. If you don\'t know the ID, then submit the';
        echo ' ClassName, and you will be provided with an interface to select the record you wish to generate a';
        echo ' fixture for.</span>';
        echo '</p>';

        echo '<button type="submit">Submit</button>';

        echo '</form>';
    }

    protected function outputClassForm(HTTPRequest $request, string $className): void
    {
        $dbFields = Config::inst()->get($className, 'db');
        $start = $request->getVar('start') ?? 0;

        /** @var PaginatedList|DataObject[] $paginatedList */
        $paginatedList = PaginatedList::create($className::get());
        // We'll show a max of 20 records per page
        $paginatedList->setPageLength(20);
        $paginatedList->setPageStart($start);

        if ($paginatedList->count() === 0) {
            echo sprintf('<p>No Database records found for ClassName: %s</p>', $className);

            return;
        }

        echo '<p><a href="/dev/tasks/generate-fixture-from-dataobject">< Back to the beginning</a></p>';

        echo '<div class="pagination">';
        echo '<p><strong>Pagination:</strong></p>';
        echo '<ul class="pagination-list">';

        if ($paginatedList->PrevLink()) {
            echo sprintf('<li><a href="%s">&leftarrow;</a></li>', $paginatedList->PrevLink());
        } else {
            echo '<li><span>&leftarrow;</span></li>';
        }

        foreach ($paginatedList->PaginationSummary() as $pageSummary) {
            if ($pageSummary->CurrentBool) {
                echo sprintf('<li><span>%s</span></li>', $pageSummary->PageNum);
            } else {
                echo sprintf('<li><a href="%s">%s</a></li>', $pageSummary->Link, $pageSummary->PageNum);
            }
        }

        if ($paginatedList->NextLink()) {
            echo sprintf('<li><a href="%s">&rightarrow;</a>', $paginatedList->NextLink());
        } else {
            echo '<li><span>&rightarrow;</span></li>';
        }

        echo '</ul>';
        echo '</div>';
        echo '<p>Please select the record you wish to generate the fixture for below:</p>';

        // Remove Datatime and Boolean fields, as they're (likely) not that useful for trying to find a specific record
        $dbFields = array_diff($dbFields, ['Datetime', 'Boolean']);
        $linkTemplate = '<td><a href="/dev/tasks/generate-fixture-from-dataobject?ClassName=%s&ID=%s">Link</a></td>';

        echo '<table>';
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

        foreach ($paginatedList as $dataObject) {
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

        echo '<form class="depth-form" action="" method="get">';
        echo sprintf('<input type="hidden" name="ClassName" value="%s" />', $className);
        echo sprintf('<input type="hidden" name="ID" value="%s" />', $id);
        echo '</label>';
        echo '<br />';
        echo '<br />';
        echo '<button type="submit">Submit</button>';
        echo '</form>';

        $service = FixtureService::create();

        $service->addDataObject($dataObject);

        echo '<div style="clear: both;"></div>';

        echo '<div class="warnings">';
        echo '<p><strong>Warnings:</strong></p>';
        echo '<ul>';

        foreach ($service->getWarnings() as $warning) {
            echo sprintf('<li>%s</li>', $warning);
        }

        echo '</ul>';
        echo '</div>';

        echo '<p><strong>Fixture output:</strong></p>';
        echo sprintf('<textarea cols="90" rows="50">%s</textarea>', $service->outputFixture());
    }

    protected function outputStyles(): void
    {
        echo '<style>';

        echo <<<'CSS'
body {
    background: #f7f7f7;
    font-family: Arial, sans-serif;
    font-size: 16px;
}

select,
input {
    padding: 4px;
    margin: 4px 0;
}

.note {
    color: #4d4d4d;
    font-size: 14px;
}

table {
    display: block;
    width: 100%;
    overflow: scroll;
    border-collapse: collapse;
}

thead,
tbody {
    border-left: 1px solid #000;
}

tbody {
    border-bottom: 1px solid #000;
}

th,
td {
    border-right: 1px solid #000;
    border-top: 1px solid #000;
    padding: 6px;
}

thead tr {
    background: #f2f0f0;
}

tbody tr:nth-child(even) {
    background: #e8e5e5;
}

.depth-form,
.warnings,
.pagination {
    border: 1px solid #a9a9a9;
    display: inline-block;
    max-width: 900px;
    padding: 12px;
}

.warnings li {
    margin-bottom: 8px;
    color: #580000;
}

.pagination-list {
    list-style-type: none;
    padding: 0;
    margin: 0;
}

.pagination-list li {
    display: inline-block;
}

.pagination-list li a,
.pagination-list li span {
    border: 1px solid #a9a9a9;
    display: block;
    margin: 0 4px;
    padding: 6px 8px;
}

.pagination-list li span {
    background: #e8e5e5;
}
CSS;

        echo '</style>';
    }

}
