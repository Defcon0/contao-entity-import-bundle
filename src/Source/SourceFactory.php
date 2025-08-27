<?php

/*
 * Copyright (c) 2021 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\Source;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\StringUtil;
use HeimrichHannot\EntityImportBundle\DataContainer\EntityImportSourceContainer;
use HeimrichHannot\EntityImportBundle\Event\SourceFactoryCreateSourceEvent;
use HeimrichHannot\UtilsBundle\Util\Utils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SourceFactory
{
    protected ContainerInterface       $container;
    protected EventDispatcherInterface $eventDispatcher;
    protected Utils                    $utils;
    protected InsertTagParser          $insertTagParser;

    /**
     * SourceFactory constructor.
     */
    public function __construct(ContainerInterface $container, EventDispatcherInterface $eventDispatcher, Utils $utils, InsertTagParser $insertTagParser)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->container = $container;
        $this->utils = $utils;
        $this->insertTagParser = $insertTagParser;
    }

    public function createInstance(int $sourceModel): ?SourceInterface
    {
        if (null === ($sourceModel = $this->utils->model()->findModelInstanceByPk('tl_entity_import_source', $sourceModel))) {
            return null;
        }

        $source = null;

        switch ($sourceModel->type) {
            case EntityImportSourceContainer::TYPE_DATABASE:
                $source = new DatabaseSource();

                break;

            case EntityImportSourceContainer::TYPE_FILE:
                switch ($sourceModel->fileType) {
                    case EntityImportSourceContainer::FILETYPE_JSON:
                        $source = new JSONFileSource($this->eventDispatcher, $this->utils, $this->insertTagParser);

                        break;

                    case EntityImportSourceContainer::FILETYPE_XML:
                        $source = new XmlFileSource($this->eventDispatcher, $this->utils, $this->insertTagParser);

                        break;

                    case EntityImportSourceContainer::FILETYPE_CSV:
                        $source = new CSVFileSource($this->eventDispatcher, $this->utils, $this->insertTagParser);

                        break;

                    case EntityImportSourceContainer::FILETYPE_RSS:
                        $source = new RSSFileSource($this->eventDispatcher, $this->utils, $this->insertTagParser);

                        break;
                }

                break;
        }

        $event = $this->eventDispatcher->dispatch(new SourceFactoryCreateSourceEvent(
            $source,
            $sourceModel
        ), SourceFactoryCreateSourceEvent::NAME);

        $source = $event->getSource();

        if (null === $source) {
            throw new \Exception('No file source class found for file type '.$sourceModel->fileType);
        }

        $source->setFieldMapping(StringUtil::deserialize($sourceModel->fieldMapping, true));
        $source->setSourceModel($sourceModel);
        $source->setContainer($this->container);

        return $source;
    }
}
