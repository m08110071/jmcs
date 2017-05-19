<?php

namespace ProSpecs\Catalog\Cron;

class CatalogInventoryImport
{

    protected $_ftp;

    protected $_logger;

    protected $_scopeConfig;

    protected $_productRepository;

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
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Catalog\Model\Product $product,
        \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModelFactory $resourceFactory,
        \Psr\Log\LoggerInterface $logger
    ){
        $this->_ftp = $ftp;
        $this->_logger = $logger;
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
        $open = $this->_ftp->open([
            'host'  =>  $this->getConfig('host'),
            'user'  =>  $this->getConfig('user'),
            'password'  =>  $this->getConfig('password'),
            'passive'   =>  true
        ]);

        if($open){
            $content = $this->_ftp->read($this->getConfig('file_name'));
            $rows = explode("\n", $content);

            foreach($rows as $row){

                $rowData = $this->preparedRowData($row);

                if($rowData){
                    $productId = $this->getIdBySKu($rowData['sku']);

                    if($productId){
                        $this->_updatedData[] = [
                            'product_id'    =>  $productId,
                            'qty'   =>  $rowData['qty']
                        ];
                    }

                    if(count($this->_updatedData) >= 10000){
                        $this->update();
                    }
                }
            }

            $this->update();

        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function update(){

        if(count($this->_updatedData) <= 0)
            return $this;

        try{
//            $this->_connection->insertOnDuplicate(
//                $this->_tableName,
//                $this->_updatedData,
//                ['product_id', 'qty']
//            );

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

        $rowData = explode(',', $rowData);

        if(count($rowData) > 1){

            $sku = str_replace(' ', '', $rowData[0]);
            $sku = str_replace('"', '', $sku);

            return [
                'sku'   =>  $sku,
                'qty'   =>  (int)$rowData[1]
            ];
        }

        return false;
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