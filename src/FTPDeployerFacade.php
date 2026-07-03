<?php

namespace InjaOnline\FTPDeployer;

use Illuminate\Support\Facades\Facade;

class FTPDeployerFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ftp-deployer';
    }
}
