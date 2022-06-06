<?php

namespace ChrisPenny\DataObjectToFixture\Helper;

use InvalidArgumentException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;

/**
 * This stuff needs to go back to the Fluent module. It's living here for now so that I can proceed.
 */
class FluentHelper
{

    /**
     * Static internal cache data
     */
    protected static array $cacheData = [];

    /**
     * @param DataObject|FluentExtension $dataObject
     */
    public static function getLocaleCodesByObjectInstance(
        DataObject $dataObject,
        string $stage = Versioned::DRAFT,
        bool $clearCache = false
    ): array {
        if (!$dataObject->hasExtension(FluentExtension::class)) {
            throw new InvalidArgumentException('DataObject does not invoke FluentExtension');
        }

        $cacheKey = 'locales_by_object_' . $dataObject->ClassName . $dataObject->ID . $stage;

        if (isset(static::$cacheData[$cacheKey])) {
            if (!$clearCache) {
                return static::$cacheData[$cacheKey];
            }

            unset(static::$cacheData[$cacheKey]);
        }

        // Get table
        $baseTable = $dataObject->baseTable();
        $table = $dataObject->getLocalisedTable($baseTable);

        if ($stage === Versioned::LIVE) {
            $table .= FluentVersionedExtension::SUFFIX_LIVE;
        }

        $query = SQLSelect::create();
        $query
            ->selectField('Locale')
            ->addFrom($table)
            ->addWhere([
                'RecordID' => $dataObject->ID,
            ]);

        $result = $query->execute();

        if ($query->execute()->value() !== null) {
            return static::$cacheData[$cacheKey] = $result->column('Locale');
        }

        return static::$cacheData[$cacheKey] = [];
    }

}
