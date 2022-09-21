<?php

namespace ChrisPenny\DataObjectToFixture\Admin\Form;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;

class ImportButton extends GridFieldImportButton
{

    /**
     * Essentially a copy/paste of the parent method. Unfortunately there isn't an easy way to update the title of the
     * GridField_FormAction that is created
     *
     * @param GridField $gridField
     * @return array
     */
    public function getHTMLFragments($gridField) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $modalID = $gridField->ID() . '_ImportModal';

        // Check for form message prior to rendering form (which clears session messages)
        $form = $this->getImportForm();
        $hasMessage = $form && $form->getMessage();

        // Render modal
        $template = SSViewer::get_templates_by_class(static::class, '_Modal');
        $viewer = new ArrayData([
            'ImportModalTitle' => $this->getModalTitle(),
            'ImportModalID' => $modalID,
            'ImportIframe' => $this->getImportIframe(),
            'ImportForm' => $this->getImportForm(),
        ]);
        $modal = $viewer->renderWith($template)->forTemplate();

        // Build action button
        $button = new GridField_FormAction(
            $gridField,
            'import',
            'Import Yaml',
            'import',
            []
        );
        $button
            ->addExtraClass('btn btn-secondary font-icon-upload btn--icon-large action_import')
            ->setForm($gridField->getForm())
            ->setAttribute('data-toggle', 'modal')
            ->setAttribute('aria-controls', $modalID)
            ->setAttribute('data-target', sprintf('#%s', $modalID))
            ->setAttribute('data-modal', $modal);

        // If form has a message, trigger it to automatically open
        if ($hasMessage) {
            $button->setAttribute('data-state', 'open');
        }

        return [
            $this->targetFragment => $button->Field(),
        ];
    }

}
