<?php

/**
 * SionModel Module
 *
 */

namespace SionModel\Service;

use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use SionModel\Db\Model\FilesTable;

/**
 * Factory responsible of priming the PatresTable service
 *
 * @author Jeff Ro <jeff.roedel.isp@gmail.com>
 */
class FilesTableFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $sionModelConfig = $container->get('SionModel\Config');
        if (! isset($sionModelConfig['files_directory']) || empty($sionModelConfig['public_files_directory'])) {
            throw new \Exception('Please specify the \'files_directory\' and \'public_files_directory\' keys to use the FilesTable.');
        }

        $dbAdapter = $container->get(\Zend\Db\Adapter\Adapter::class);

        /** @var  User $userService **/
        $userService = $container->get('zfcuser_user_service');
        $user = $userService->getAuthService()->getIdentity();
        $actingUserId = $user ? $user->id : null;

        $table = new FilesTable($dbAdapter, $container, $actingUserId, $sionModelConfig);

        return $table;
    }
}
