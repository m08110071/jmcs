<?php

namespace ProSpecs\Catalog\Cron;

class MusicCatalogInventoryImport
{

    protected $_ftp;

    protected $_logger;

    protected $_scopeConfig;

    protected $_productRepository;

    protected $_directoryList;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModelFactory
     */
    protected $_resourceFactory;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModel
     */
    protected $_resource;

    protected $_tableName;

    /**
     * DB connection.
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    protected $_updatedData = [];

    public function __construct(
        \Magento\Framework\Filesystem\Io\Ftp $ftp,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Catalog\Model\Product $product,
        \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModelFactory $resourceFactory,
        \Psr\Log\LoggerInterface $logger
    ){
        $this->_ftp = $ftp;
        $this->_logger = $logger;
        $this->_directoryList = $directoryList;
        $this->_scopeConfig = $scopeConfig;
        $this->_productRepository = $productRepositoryInterface;
        $this->_resourceFactory = $resourceFactory;
        $this->_connection = $product->getResource()->getConnection();

        $this->_tableName = $this->getResource()->getTable(
            'cataloginventory_stock_item'
        );
    }

    /**
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        $i= 0;
        $handle = fopen($this->_directoryList->getPath('var') . '/import/stockv2@ingram_000001.dat', "r") or die("Couldn't get handle");
        if ($handle) {
            while (!feof($handle)) {
                $row = fgets($handle, 4096);
                // Process buffer here..

                $rowData = $this->preparedRowData($row);

                if($rowData){
                    $productId = $this->getIdBySKu($rowData['sku']);

                    if($productId){
                        $this->_updatedData[] = [
                            'product_id'    =>  $productId,
                            'qty'   =>  $rowData['qty']
                        ];
                    }

                    $this->_logger->info($i++);

                    if(count($this->_updatedData) >= 10000){
                        $this->update();
                        $i = 0;
                    }
                }
            }
            fclose($handle);
        }

        $this->update();

        return $this;
    }

    /**
     * @return $this
     */
    protected function update(){

        if(count($this->_updatedData) <= 0)
            return $this;

        try{
            $this->_connection->insertOnDuplicate(
                $this->_tableName,
                $this->_updatedData,
                ['product_id', 'qty']
            );

            $this->_updatedData = [];

        }catch (\Exception $e){
            $this->_logger->critical($e);
        }

        return $this;
    }

    /**
     * @return Proxy\Product\ResourceModel
     */
    protected function getResource()
    {
        if (!$this->_resource) {
            $this->_resource = $this->_resourceFactory->create();
        }
        return $this->_resource;
    }

    /**
     * @param $rowData
     * @return array|bool
     */
    protected function preparedRowData($rowData){

        $sku = substr($rowData, 14, 14);

        $qtyPositions = [
            38,
            45,
            52,
            59,
            66,
            73,
            80,
            87
        ];

        $qty = 0;

        foreach($qtyPositions as $qtyPosition){
            $qty += intval(substr($rowData, $qtyPosition[0], 7));
        }

        return [
            'sku'   =>  $sku,
            'qty'   =>  $qty
        ];
    }

    /**
     * @param $sku
     * @return bool|int|null
     */
    protected function getIdBySKu($sku){
        try{
            $product = $this->_productRepository->get($sku);
        }catch (\Magento\Framework\Exception\NoSuchEntityException $e){
            return false;
        }

        return $product->getId();
    }

    /**
     * @param $field
     * @return mixed
     */
    protected function getConfig($field){
        return $this->_scopeConfig->getValue('ingram_inventory/ftp/' . $field);
    }
}