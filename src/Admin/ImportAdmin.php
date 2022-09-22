<?php

namespace ChrisPenny\DataObjectToFixture\Admin;

use ChrisPenny\DataObjectToFixture\Admin\Form\ImportButton;
use ChrisPenny\DataObjectToFixture\Admin\Model\ImportHistory;
use ChrisPenny\DataObjectToFixture\Service\DataObjectService;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\PermissionProvider;
use Symbiote\GridFieldExtensions\GridFieldAddExistingSearchButton;
use Throwable;

class ImportAdmin extends ModelAdmin implements PermissionProvider
{

    private const PERMISSION_ACCESS = 'DataObjectToFixture_ImportAdmin';

    private static string $url_segment = 'fixture-import';

    private static string $menu_title = 'Fixture Import';

    private static string $required_permission_codes = self::PERMISSION_ACCESS;

    private static string $managed_models = ImportHistory::class;

    private static array $allowed_actions = [
        'ImportForm',
    ];

    private static array $url_handlers = [
        'import' => 'import',
    ];

    public function getEditForm($id = null, $fields = null) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $form = parent::getEditForm($id, $fields);

        /** @var GridField $gridField */
        $gridField = $form->Fields()->fieldByName('ChrisPenny-DataObjectToFixture-Admin-Model-ImportHistory');

        if ($gridField) {
            $config = $gridField->getConfig();

            // Remove default Components
            $config->removeComponentsByType(GridFieldImportButton::class);
            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $config->removeComponentsByType(GridFieldAddExistingSearchButton::class);
            $config->removeComponentsByType(GridFieldPrintButton::class);
            $config->removeComponentsByType(GridFieldExportButton::class);

            // Add our own ImportButton (that contains the correct naming)
            $config->addComponent(
                ImportButton::create('buttons-before-left')
                    ->setImportForm($this->ImportForm())
                    ->setModalTitle('Import from Yaml')
            );
        }

        return $form;
    }

    public function ImportForm() // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fields = FieldList::create([
            FileField::create('_YmlFile', 'Upload yml file')
                ->setAllowedExtensions(['yml']),
        ]);

        $actions = FieldList::create([
            FormAction::create('import', 'Import from yaml')
                ->addExtraClass('btn btn-primary'),
        ]);

        $form = new Form(
            $this,
            'ImportForm',
            $fields,
            $actions
        );
        $form->setFormAction(Controller::join_links($this->Link(), 'ImportForm'));

        return $form;
    }

    public function import($data, $form, $request) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fileName = $_FILES['_YmlFile']['tmp_name'] ?? null;

        // File wasn't properly uploaded, show a reminder to the user
        if (!$fileName || !file_get_contents($fileName)) {
            $form->sessionMessage('Please browse for a yaml file to import');
            $this->redirectBack();

            return false;
        }

        /*
         * Populate uses DB::alterationMessage() to display messages when the dev task is run.
         * This function echos the message immediately so we need to suppress it here otherwise the messages will
         * appear briefly when the page reloads
         */
        DB::quiet();

        try {
            $service = new DataObjectService();
            $service->importFromStream($fileName);
        } catch (Throwable $e) {
            // database exceptions are especially ugly so it is best to simplify this for the CMS users experience
            $message = $e instanceof DatabaseException
                ? 'A Database error has occurred. This may be caused by referencing a non existant DataObject or Field.'
                    . ' Some of the Objects defined in this file may still have been imported.'
                : $e->getMessage();

            $form->sessionMessage($message);
            $this->redirectBack();

            return false;
        }

        DB::quiet(false);

        $importHistory = ImportHistory::create();
        $importHistory->Filename = $_FILES['_YmlFile']['name'];
        $importHistory->write();

        $form->sessionMessage('Successfully imported fixture', ValidationResult::TYPE_GOOD);
        $this->redirectBack();

        return true;
    }

}
