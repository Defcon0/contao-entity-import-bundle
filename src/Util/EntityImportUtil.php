<?php

/*
 * Copyright (c) 2021 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\Util;

use Contao\DcaLoader;
use Contao\System;

class EntityImportUtil
{
    public function transformFieldMappingSourceValueToSelect($options)
    {
        $dca = &$GLOBALS['TL_DCA']['tl_entity_import_source']['fields']['fieldMapping']['eval']['multiColumnEditor']['fields']['sourceValue'];

        $dca['inputType'] = 'select';
        $dca['options'] = $options;
        $dca['eval']['includeBlankOption'] = true;
        $dca['eval']['mandatory'] = true;
        $dca['eval']['chosen'] = true;
    }

    public function getLocalizedFieldName(string $strField, string $strTable): ?string
    {
        $loader = new DcaLoader($strTable);
        $loader->load();
        System::loadLanguageFile($strTable);

        return $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['label'][0] ?: $strField;
    }
}
