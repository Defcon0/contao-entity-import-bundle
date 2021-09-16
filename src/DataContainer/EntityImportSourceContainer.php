<?php

/*
 * Copyright (c) 2021 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\DataContainer;

use Contao\Database;
use Contao\DataContainer;
use Contao\Message;
use Contao\Model;
use Contao\System;
use HeimrichHannot\EntityImportBundle\Event\AddSourceFieldMappingPresetsEvent;
use HeimrichHannot\EntityImportBundle\Source\AbstractFileSource;
use HeimrichHannot\EntityImportBundle\Source\CSVFileSource;
use HeimrichHannot\EntityImportBundle\Source\RSSFileSource;
use HeimrichHannot\EntityImportBundle\Source\SourceFactory;
use HeimrichHannot\EntityImportBundle\Util\EntityImportUtil;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EntityImportSourceContainer
{
    const TYPE_DATABASE = 'db';
    const TYPE_FILE = 'file';

    const TYPES = [
        self::TYPE_DATABASE,
        self::TYPE_FILE,
    ];

    const RETRIEVAL_TYPE_HTTP = 'http';
    const RETRIEVAL_TYPE_CONTAO_FILE_SYSTEM = 'contao_file_system';
    const RETRIEVAL_TYPE_ABSOLUTE_PATH = 'absolute_path';

    const RETRIEVAL_TYPES = [
        self::RETRIEVAL_TYPE_HTTP,
        self::RETRIEVAL_TYPE_CONTAO_FILE_SYSTEM,
        self::RETRIEVAL_TYPE_ABSOLUTE_PATH,
    ];

    const FILETYPE_CSV = 'csv';
    const FILETYPE_JSON = 'json';
    const FILETYPE_RSS = 'rss';

    const FILETYPES = [
        self::FILETYPE_CSV,
        self::FILETYPE_JSON,
        self::FILETYPE_RSS,
    ];

    protected $activeBundles;
    protected $database;
    protected $cache;

    protected ModelUtil $modelUtil;
    protected SourceFactory $sourceFactory;
    protected EntityImportUtil $util;
    protected EventDispatcherInterface $eventDispatcher;
    protected DatabaseUtil $databaseUtil;

    public function __construct(SourceFactory $sourceFactory, ModelUtil $modelUtil, EntityImportUtil $util, EventDispatcherInterface $eventDispatcher, DatabaseUtil $databaseUtil)
    {
        $this->activeBundles = System::getContainer()->getParameter('kernel.bundles');
        $this->sourceFactory = $sourceFactory;
        $this->modelUtil = $modelUtil;
        $this->util = $util;
        $this->eventDispatcher = $eventDispatcher;
        $this->databaseUtil = $databaseUtil;
    }

    public function setPreset(?DataContainer $dc)
    {
        if (!($preset = $dc->activeRecord->fieldMappingPresets)) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA']['tl_entity_import_source'];

        $this->databaseUtil->update('tl_entity_import_source', [
            'fieldMappingPresets' => '',
            'fieldMapping' => serialize($dca['fields']['fieldMappingPresets']['eval']['presets'][$preset]),
        ], 'tl_entity_import_source.id='.$dc->id);
    }

    public function initPalette(?DataContainer $dc)
    {
        if (null === ($sourceModel = $this->modelUtil->findModelInstanceByPk($dc->table, $dc->id))) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA'][$dc->table];

        // database
        switch ($sourceModel->type) {
            case static::TYPE_DATABASE:
                if (!$sourceModel->dbSourceTable) {
                    $dca['palettes'][static::TYPE_DATABASE] = str_replace('fieldMappingCopier', '', $dca['palettes'][static::TYPE_DATABASE]);
                    $dca['palettes'][static::TYPE_DATABASE] = str_replace('fieldMapping', '', $dca['palettes'][static::TYPE_DATABASE]);
                } else {
                    try {
                        $options = array_values(Database::getInstance($sourceModel->row())->getFieldNames($sourceModel->dbSourceTable, true));

                        $this->util->transformFieldMappingSourceValueToSelect(
                            array_combine($options, $options)
                        );
                    } catch (\Exception $e) {
                    }
                }

                break;

            case static::TYPE_FILE:
                $fileType = $sourceModel->fileType;

                switch ($fileType) {
                    case static::FILETYPE_CSV:
                        /** @var CSVFileSource $source */
                        $source = $this->sourceFactory->createInstance($sourceModel->id);

                        $options = [];
                        $fields = $source->getHeadingLine();

                        foreach ($fields as $index => $field) {
                            if ($sourceModel->csvHeaderRow) {
                                $options[' '.$index] = $field.' ['.$GLOBALS['TL_LANG']['MSC']['entityImport']['column'].' '.$index.']';
                            } else {
                                $options[' '.$index] = $GLOBALS['TL_LANG']['MSC']['entityImport']['column'].' '.$index;
                            }
                        }

                        $this->util->transformFieldMappingSourceValueToSelect(
                            $options
                        );

                        $dca['fields']['fileContent']['eval']['rte'] = 'ace';

                        break;

                    case static::FILETYPE_JSON:
                        $dca['fields']['fileContent']['eval']['rte'] = 'ace|json';

                        break;

                    case static::FILETYPE_RSS:
                        /** @var RSSFileSource $source */
                        $source = $this->sourceFactory->createInstance($sourceModel->id);

                        $options = $source->getPostFieldsAsOptions();

                        $this->util->transformFieldMappingSourceValueToSelect(
                            array_combine($options, $options)
                        );

                        $dca['fields']['fileContent']['eval']['rte'] = 'ace|xml';

                        break;

                    default:
                        break;
                }

                break;
        }

        // field mapping presets
        $event = $this->eventDispatcher->dispatch(new AddSourceFieldMappingPresetsEvent([], $sourceModel), AddSourceFieldMappingPresetsEvent::NAME);

        $presets = $event->getPresets();

        if (empty($presets)) {
            unset($dca['fields']['fieldMappingPresets']);
        } else {
            $options = array_keys($presets);

            asort($presets);

            $dca['fields']['fieldMappingPresets']['options'] = $options;
            $dca['fields']['fieldMappingPresets']['eval']['presets'] = $presets;
        }
    }

    public function onLoadFileContent(?string $value, ?DataContainer $dc)
    {
        if (null === ($sourceModel = $this->modelUtil->findModelInstanceByPk('tl_entity_import_source', $dc->id))) {
            return '';
        }

        if ($sourceModel->type !== static::TYPE_FILE) {
            return '';
        }

        if (!$sourceModel->fileType) {
            return '';
        }

        return $this->getFileContent($sourceModel);
    }

    public function getFileContent(Model $sourceModel)
    {
        /** @var AbstractFileSource $source */
        $source = $this->sourceFactory->createInstance($sourceModel->id);

        switch ($sourceModel->fileType) {
            case static::FILETYPE_CSV:
                return $source->getLinesFromFile(25, true)."\n...";

            case static::FILETYPE_JSON:
                $string = json_decode($source->getFileContent(true));

                return substr(json_encode($string, JSON_PRETTY_PRINT), 0, 50000);

            case static::FILETYPE_RSS:
                return substr($source->getFileContent(true), 0, 50000);

                break;
        }

        return $source->getFileContent(true);
    }

    public function getAllTargetTables(?DataContainer $dc): array
    {
        if (null === ($source = $this->modelUtil->findModelInstanceByPk('tl_entity_import_source', $dc->id))) {
            return [];
        }

        try {
            $options = array_values(Database::getInstance($source->row())->listTables(null, true));
        } catch (\Exception $e) {
            Message::addError(sprintf($GLOBALS['TL_LANG']['MSC']['entityImport']['dbConnectionError'], $e->getMessage()));

            return [];
        }

        return $options;
    }
}
