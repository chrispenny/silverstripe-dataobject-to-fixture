<?php

namespace ChrisPenny\DataObjectToFixture\Extension;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Permission;

/**
 * Handles adding the "Export Fixture" button to a page's secondary actions menu
 */
class SiteTreeExtension extends DataExtension
{

    public function updateCMSActions(FieldList $actions): void
    {
        // Check permissions
        if (!$this->owner->canEdit() || !$this->owner->canPublish() || !Permission::check('CMS_ACCESS_CMSMain')) {
            return;
        }

        $actionOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if (!$actionOptions) {
            return;
        }

        $actionOptions->insertAfter(
            'Information',
            FormAction::create('exportfixture', _t('EXPORTFIXTURE', 'Export Fixture'))
                    ->setUseButtonTag(false)
                    ->addExtraClass('export-fixture-action')
                    ->addExtraClass('btn')
                    ->addExtraClass('btn-secondary'),
        );
    }

}
