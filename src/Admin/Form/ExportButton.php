<?php

namespace ChrisPenny\DataObjectToFixture\Admin\Form;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridFieldExportButton;

class ExportButton extends GridFieldExportButton
{

    public function getHTMLFragments($gridField) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $button = new GridField_FormAction(
            $gridField,
            'export',
            'Export bulk fixtures',
            'export',
            null
        );
        $button->addExtraClass('btn btn-secondary no-ajax font-icon-down-circled action_export');
        $button->setForm($gridField->getForm());

        return [
            $this->targetFragment => $button->Field(),
        ];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data) // phpcs:ignore
    {
        if ($actionName === 'export') {
            // Get DataObject
            $pages = SiteTree::get()->filter(['BulkFixtureExport' => 1]);
            $service = FixtureService::create();

            foreach ($pages as $page) {
                $service->addDataObject($page);
            }

            $output = $service->outputFixture();
            // Configurate file name with current date
            $now = date('d-m-Y-H-i');
            $fileName = 'bulk-export-'.$now.'.yml';

            // Download object
            if ($output) {
                return HTTPRequest::send_file($output, $fileName, 'application/x-yaml');
            }

            return null;
        }

        return null;
    }

}
