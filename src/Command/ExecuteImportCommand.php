<?php

/*
 * Copyright (c) 2021 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\Command;

use Contao\CoreBundle\Command\AbstractLockedCommand;
use HeimrichHannot\EntityImportBundle\DataContainer\EntityImportConfigContainer;
use HeimrichHannot\EntityImportBundle\Importer\ImporterFactory;
use HeimrichHannot\EntityImportBundle\Importer\ImporterInterface;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ExecuteImportCommand extends AbstractLockedCommand
{
    protected InputInterface $input;
    protected SymfonyStyle $io;
    protected ModelUtil $modelUtil;
    protected ImporterFactory $importerFactory;

    /**
     * ExecuteImportCommand constructor.
     */
    public function __construct(ModelUtil $modelUtil, ImporterFactory $importerFactory)
    {
        $this->modelUtil = $modelUtil;
        $this->importerFactory = $importerFactory;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('huh:entity-import:execute');
        $this->setDescription('Runs a given importer config on the command line.');
        $this->addArgument('config-ids', InputArgument::REQUIRED, 'The importer config ids as a comma separated list');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run importer without making changes to the database.');
        $this->addOption('web-cron-mode', null, InputOption::VALUE_NONE, 'Companion cron for the website import function. See README for details.');
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->io = new SymfonyStyle($input, $output);
        $this->framework = $this->getContainer()->get('contao.framework');
        $this->framework->initialize();

        $this->import();

        return 0;
    }

    private function import()
    {
        $configIds = explode(',', $this->input->getArgument('config-ids'));
        $dryRun = $this->input->getOption('dry-run') ?: false;
        $webCronMode = $this->input->getOption('web-cron-mode') ?: false;

        foreach ($configIds as $configId) {
            if (null === ($configModel = $this->modelUtil->findModelInstanceByPk('tl_entity_import_config', $configId))) {
                $this->io->error("Importer config with ID $configId not found.");

                continue;
            }

            if (!$configModel->useCron) {
                $this->io->warning("Importer with config ID $configId hasn't set useCron=1. Skipped.");

                continue;
            }

            if ($webCronMode && $configModel->useCronInWebContext) {
                if (EntityImportConfigContainer::STATE_READY_FOR_IMPORT !== $configModel->state) {
                    continue;
                }

                // no support for continuing an already started import
                if ($configModel->importStarted) {
                    continue;
                }

                $configModel->importStarted = time();
                $configModel->importProgressCurrent = 0;
                $configModel->save();
            }

            if ($configModel->cronLanguage) {
                $language = $GLOBALS['TL_LANGUAGE'];

                $GLOBALS['TL_LANGUAGE'] = $configModel->cronLanguage;
            }

            /** @var ImporterInterface $importer */
            $importer = $this->importerFactory->createInstance($configModel->id);
            $importer->setDryRun($dryRun);
            $importer->setWebCronMode($webCronMode);
            $importer->setInputOutput($this->io);
            $result = $importer->run();

            if ($webCronMode && $configModel->useCronInWebContext && EntityImportConfigContainer::STATE_READY_FOR_IMPORT === $configModel->state) {
                // might have been changed
                $configModel->refresh();

                $configModel->importFinished = time();

                if ('success' === $result['state']) {
                    $configModel->state = EntityImportConfigContainer::STATE_SUCCESS;
                } else {
                    $configModel->state = EntityImportConfigContainer::STATE_FAILED;
                }

                $configModel->save();
            }

            $importer->outputFinalResultMessage($result);

            if ($configModel->cronLanguage) {
                $GLOBALS['TL_LANGUAGE'] = $language;
            }
        }
    }
}
