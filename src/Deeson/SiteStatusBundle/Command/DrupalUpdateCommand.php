<?php

namespace Deeson\SiteStatusBundle\Command;

use Deeson\SiteStatusBundle\Document\Module;
use Deeson\SiteStatusBundle\Document\Site;
use Deeson\SiteStatusBundle\Services\DrupalUpdateRequestService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Deeson\SiteStatusBundle\Managers\SiteManager;
use Deeson\SiteStatusBundle\Managers\ModuleManager;

class DrupalUpdateCommand extends ContainerAwareCommand {

  /**
   * @var DrupalUpdateRequestService
   */
  protected $drupalUpdateService;

  /**
   * @var string
   */
  protected $latestReleaseVersion;

  /**
   * @var bool
   */
  protected $isSecurityRelease = FALSE;

  protected function configure() {
    $this->setName('deeson:site-status:drupal-update')
      ->setDescription('Update core & all the modules with the latest versions from Drupal.org')
      ->addOption('import-new', NULL, InputOption::VALUE_NONE, 'If set will only import data on newly created sites');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    /** @var DrupalUpdateRequestService drupalUpdateService */
    $this->drupalUpdateService = $this->getContainer()->get('drupal_update_service');

    /** @var SiteManager $siteManager */
    $siteManager = $this->getContainer()->get('site_manager');
    /** @var ModuleManager $moduleManager */
    $moduleManager = $this->getContainer()->get('module_manager');

    //$updateNewOnly = isset($input->getOption('import-new'));

    // @todo refactor this!

    $moduleLatestVersion = array();
    $majorVersions = $siteManager->getAllMajorVersionReleases();

    foreach ($majorVersions as $version) {
      $modules = $moduleManager->getAllByVersion($version);
      foreach ($modules as $module) {
        /** @var Module $module */
        $output->writeln('Updating - ' . $module->getProjectName() . ' for version: ' . $version);

        try {
          $this->processDrupalUpdateData($module->getProjectName(), $version);
        } catch (\Exception $e) {
          $output->writeln(' - Unable to update module version [' . $version . ']: ' . $e->getMessage());
          continue;
        }

        $securityToken = ($this->isSecurityRelease ? 'Y' : 'N');
        $output->writeln("\tsecurity: $securityToken");

        $moduleLatestVersion[$version][$module->getProjectName()] = array(
          'version' => $this->latestReleaseVersion,
          'isSecurity' => ($this->isSecurityRelease ? 1 : 0),
        );

        $module->setName($this->drupalUpdateService->getModuleName());
        $module->setIsNew(FALSE);
        $module->setLatestVersion($version, $this->latestReleaseVersion, $this->isSecurityRelease);
        $moduleManager->updateDocument();
      }
    }

    foreach ($majorVersions as $version) {
      // Update the core after the modules to update the versions of the modules
      // for a site.
      $output->writeln('Updating - Drupal version: ' . $version);

      try {
        $this->processDrupalUpdateData('drupal', $version);
      } catch (\Exception $e) {
        $output->writeln(' - Unable to update module version [' . $version . ']: ' . $e->getMessage());
      }

      $sites = $siteManager->getAllByVersion($version);
      foreach ($sites as $site) {
        /** @var Site $site */
        $output->writeln('Updating site: ' . $site->getId() . ' - ' . $site->getUrl());

        if (!isset($moduleLatestVersion[$version])) {
          $output->writeln('No module version for version: ' . $version);
          continue;
        }

        $site->setLatestCoreVersion($this->latestReleaseVersion);
        $site->setModulesLatestVersion($moduleLatestVersion[$version]);
        $siteManager->updateDocument();
      }
    }
  }

  /**
   * Gets the latest information on a module from Drupal.org.
   *
   * @param string $moduleName
   * @param int $version
   *
   * @throws \Exception
   */
  protected function processDrupalUpdateData($moduleName, $version) {
    try {
      $this->drupalUpdateService->setModuleRequestName($moduleName);
      $this->drupalUpdateService->setModuleRequestVersion($version);
      $this->drupalUpdateService->processRequest();
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }

    $latestRelease = $this->drupalUpdateService->getModuleLatestRelease();
    $latestReleaseVersion = (string) $latestRelease->version;

    $isSecurityRelease = FALSE;
    if (isset($latestRelease->terms)) {
      foreach ($latestRelease->terms->term as $term) {
        //print "{$term->name} => {$term->value}\n";
        if (strtolower($term->value) == 'security update') {
          $isSecurityRelease = TRUE;
          break;
        }
      }
    }

    $this->latestReleaseVersion = $latestReleaseVersion;
    $this->isSecurityRelease = $isSecurityRelease;
  }

}