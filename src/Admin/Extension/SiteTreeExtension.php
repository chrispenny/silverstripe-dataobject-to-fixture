<?php

namespace ChrisPenny\DataObjectToFixture\Admin\Extension;

use ChrisPenny\DataObjectToFixture\Admin\ImportAdmin;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Permission;

/**
 * Handles adding the "Export Fixture" button to a page's secondary actions menu
 */
class SiteTreeExtension extends DataExtension
{

    private static $db = [
        'BulkFixtureExport' => 'Boolean',
    ];

    private static $defaults = [
        'BulkFixtureExport' => 0,
    ];

    public function updateCMSActions(FieldList $actions): void
    {
        // Check permissions
        if (!$this->owner->canEdit() || !Permission::check(ImportAdmin::PERMISSION_EXPORT)) {
            return;
        }

        $actionOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if (!$actionOptions) {
            return;
        }

        $actionOptions->insertAfter(
            'Information',
            FormAction::create('exportfixture', _t('DataObjectToFixture.ExportFixture', 'Export Fixture'))
                ->setUseButtonTag(false)
                ->addExtraClass('export-fixture-action')
                ->addExtraClass('btn')
                ->addExtraClass('btn-secondary'),
        );
    }

    public function updateSettingsFields(FieldList $fields): void
    {
        $fields->addFieldToTab(
            'Root.Settings',
            new CheckboxField('BulkFixtureExport', 'Include in bulk fixture export?'),
            'Visibility'
        );
    }

}