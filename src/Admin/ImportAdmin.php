<?php

namespace ChrisPenny\DataObjectToFixture\Admin;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\PermissionProvider;

class ImportAdmin extends LeftAndMain implements PermissionProvider
{

    private const PERMISSION_ACCESS = 'DataObjectToFixture_ImportAdmin';

    private static string $url_segment = 'fixture-import';

    private static string $menu_title = 'Fixture Import';

    private static string $required_permission_codes = self::PERMISSION_ACCESS;

    private static array $url_handlers = [
        'import' => 'import',
    ];

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $fields = FieldList::create([
            FileField::create('_YmlFile', 'Upload yml file')
                ->setAllowedExtensions(['yml']),
        ]);

        $actions = FieldList::create([
            FormAction::create('import', 'Import from yaml')
                ->addExtraClass('btn btn-primary'),
        ]);

        $form->setFields($fields);
        $form->setActions($actions);

        return $form;
    }

    public function import(array $data, Form $form, HTTPRequest $request): bool
    {
        // File wasn't properly uploaded, show a reminder to the user
        if (empty($_FILES['_YmlFile']['tmp_name']) || file_get_contents($_FILES['_YmlFile']['tmp_name']) == '') {
            $form->sessionMessage('Please browse for a yaml file to import1');

            return false;
        }

        $form->sessionMessage('Successfully imported fixture', ValidationResult::TYPE_GOOD);

        return true;
    }

}
