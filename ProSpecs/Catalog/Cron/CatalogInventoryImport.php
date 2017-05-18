<?php

namespace ProSpecs\Catalog\Cron;

class CatalogInventoryImport
{

    protected $_ftp;

    protected $_logger;

    public function __construct(
        \Magento\Framework\Filesystem\Io\Ftp $ftp,
        \Psr\Log\LoggerInterface $logger
    ){
        $this->_ftp = $ftp;
        $this->_logger = $logger;
    }

    public function execute()
    {
        $open = $this->_ftp->open([
            'host'  =>  'ftp.dlptest.com',
            'user'  =>  'dlpuser@dlptest.com',
            'password'  =>  '8PxNA0QuFVZozwA',
            'passive'   =>  true
        ]);

        if($open){
            $content = $this->_ftp->read('ICSOutput.xml');
            $this->_logger->info($content);

        }

        return $this;
    }
}