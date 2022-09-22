<?php

namespace ChrisPenny\DataObjectToFixture\Admin\Extension;

use ChrisPenny\DataObjectToFixture\Admin\ImportAdmin;
use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use SilverStripe\Admin\LeftAndMainExtension;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class ExportFixtureExtension extends LeftAndMainExtension
{

    /**
     * RequestHandler allowed actions for users with permission to export
     */
    private static array $allowed_actions = [
        'exportFixture' => ImportAdmin::PERMISSION_EXPORT,
    ];

    public function exportFixture(): ?HTTPResponse
    {
        // Get DataObject ClassName and ID
        $className = $this->owner->getRequest()->requestVar('ClassName') ?? null;
        $id = $this->owner->getRequest()->requestVar('ID') ?? null;

        if (!$className || !$id || !(Injector::inst()->get($className) instanceof DataObject)) {
            return null;
        }

        // Get DataObject
        $dataObject = $className::get_by_id($id);
        $service = FixtureService::create();
        $service->addDataObject($dataObject);
        $output = $service->outputFixture();
        // Configurate file name with current date
        $now = date('d-m-Y-H-i');
        $fileName = $className.'-'.$id.'-snapshot-'.$now.'.yml';

        // Download object
        if ($output) {
            return HTTPRequest::send_file($output, $fileName, 'application/x-yaml');
        }

        return null;
    }

}
