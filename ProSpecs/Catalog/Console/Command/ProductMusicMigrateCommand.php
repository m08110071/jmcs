<?php

namespace ProSpecs\Catalog\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\App\State as AppState;

class ProductMusicMigrateCommand extends Command
{
    const LINK_TYPE = 'related';

    const INPUT_KEY_FILENAME = 'file_name';

    const DEFAULT_FILENAME = 'RELATED_COMMODITY_A.csv';

    const SKU = 'EAN';
    const NAME = 'Title';
    const NAME2 = 'Title2';
    const PRICE = 'Price';
    const BRAND = 'Brand';
    const UPC = 'UPC';
    const MPN = 'MPN';
    const LENGTH = 'Length';
    const WIDTH = 'Width';
    const HEIGHT = 'Height';
    const Qty = 'Qty';

    /**
     * @var \Magento\Catalog\Model\Product
     */
    protected $_productModel;

    /**
     * @var AppState
     */
    protected $appState;

    protected $_csvProcessor;

    protected $_directoryList;

    protected $_productLinkResource;

    protected $_productRepository;

    protected $_productAttributeRepository;

    /** @var  OutputInterface */
    protected $_output;



    protected $_attributeLabelToId = [];

    protected $_headersIndex = [];

    protected $_fields = [
        self::SKU,
        self::PRICE,
        self::UPC
    ];

    public function __construct(
        AppState $appState,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\File\Csv $csvProcessor,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Catalog\Model\ResourceModel\Product\Link $productLinkResource,
        \Magento\Catalog\Model\Product\Attribute\Repository $productAttributeRepository,
        $name = null
    ){
        $this->appState = $appState;
        $this->_csvProcessor = $csvProcessor;
        $this->_directoryList = $directoryList;
        $this->_productRepository = $productRepositoryInterface;
        $this->_productLinkResource = $productLinkResource;
        $this->_productAttributeRepository = $productAttributeRepository;
        $this->_productModel = $productFactory;

        parent::__construct($name);
    }

    /**
     * Get list of options and arguments for the command
     *
     * @return mixed
     */
    public function getInputList()
    {
        return [
            new InputArgument(
                self::INPUT_KEY_FILENAME,
                InputArgument::OPTIONAL,
                'Default file name: ' . self::DEFAULT_FILENAME . ' be used if file name argument is not requested.'
            ),
        ];
    }

    protected function configure()
    {
        $this->setName('catalog:product-music:migrate')
            ->setDescription('Migrate product')
            ->setDefinition($this->getInputList());
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output){

        $this->appState->setAreaCode('catalog');

        $this->_output = $output;

        $output->writeln('<info>Starting To Migrate Product Data</info>');

        $migrateData = $this->loadFileData($input);

        if(is_array($migrateData)){
            $headers = array_shift($migrateData);

            $headerErrors = $this->validateHeaders($headers);

            if(count($headerErrors)){
                foreach($headerErrors as $error){
                    $output->writeln('<error>'. $error .'</error>');
                }
            }else{
                foreach($migrateData as $rowNum =>  $rowData){

                    if($this->isValidData($rowData, $rowNum + 2, $output)){
                        $this->saveProduct($rowData, $rowNum + 2);
                    }
                }
            }
        }else{
            $output->writeln('<error>'. $migrateData .'</error>');
        }

        $output->writeln('<info>The Product Migration Process Has Finished</info>');
    }

    /**
     * @param $rowData
     * @param $rowNum
     * @return $this
     */
    protected function saveProduct($rowData, $rowNum){

        $data = $this->prepareData($rowData);

        if($productId = $this->isExitedSku($data[self::SKU])){
            $this->showSuccess('[DUPLICATE][' . $rowNum . '][' . $data[self::SKU] . ']');

            return $this;
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->_productModel->create();
        $product->setSku($data[self::SKU]); // Set your sku here

        $product->setStoreId(0);

        $product->setName($data[self::SKU]); // Name of Product
        $product->setWebsiteIds([1]);
        $product->setAttributeSetId(4);
        $product->setStatus(1);
        $product->setWeight(10);
        $product->setUpc($data[self::UPC]);
        $product->setVisibility(4);
        $product->setTaxClassId(0);
        $product->setTypeId('simple');
        $product->setPrice($data[self::PRICE]);
        //$product->setCategoryIds([3]);
        $product->setStockData(
            array(
                'use_config_manage_stock' => 0,
                'manage_stock' => 1,
                'is_in_stock' => 1,
                'qty' => $data[self::Qty]
            )
        );

        try{
            $product->save();
        }catch (\Exception $e){
            $this->showError('[ERROR][' . $rowNum . ']' . $e->getMessage());
        }

        $this->showSuccess('[SUCCESS][' . $rowNum . '][' . $data[self::SKU] . ']');

        return $this;
    }

    /**
     * @param $rowData
     * @return array
     */
    public function prepareData($rowData){
        $result = [];

        foreach($this->_headersIndex as $columnName => $index){
            $result[$columnName] = $rowData[$index];
        }

        $qty = 0;

        for($i=1;$i<=8;$i++){
            $qty += $rowData[$this->_headersIndex[self::Qty . $i]];
        }

        $result[self::Qty] = $qty;

        return $result;
    }

    /**
     * @param $rowData
     * @param $rowNum
     * @return bool
     */
    protected function isValidData($rowData, $rowNum){
        $errors = $this->validateRowData($rowData);

        if(count($errors)){
            foreach($errors as $error){
                $this->showError('[' . $rowNum . '] ' .$error);
            }

            return false;
        }

        return true;
    }

    /**
     * @param $rowData
     * @return array
     */
    protected function validateRowData($rowData){

        $errors = [];

        $skuIndex = $this->_headersIndex[self::SKU];
        //$nameIndex = $this->_headersIndex[self::NAME];
//        $brandIndex = $this->_headersIndex[self::BRAND];
//        $categoryIndex = $this->_headersIndex[self::CATEGORY];
//        $upcIndex = $this->_headersIndex[self::UPC];

        foreach($this->_headersIndex as $index){
            if(!isset($rowData[$index]))
                return ['Data is invalid'];
        }

        if($rowData[$skuIndex] == ''){
            $errors[] = 'Column: ' . self::SKU . ', is required field';
        }

//        if($rowData[$nameIndex] == ''){
//            $errors[] = 'Column: ' . self::NAME . ', is required field';
//        }

//        if($rowData[$brandIndex] == ''){
//            $errors[] = 'Column: ' . self::BRAND . ', is required field';
//        }else{
//            if(!$this->getAttributeOptionIdByLabel('', $rowData[$brandIndex])){
//                $errors[] = 'Column: ' . self::BRAND . ', value "' . $rowData[$brandIndex] . '" is invalid';
//            }
//        }
//
//        if($rowData[$upcIndex] == ''){
//            $errors[] = 'Column: ' . self::UPC . ', is required field';
//        }

        return $errors;
    }

    /**
     * @param $sku
     * @return bool|int|null
     */
    protected function isExitedSku($sku){
        try{
            $product = $this->_productRepository->get($sku);
        }catch (\Magento\Framework\Exception\NoSuchEntityException $e){
            return false;
        }

        return $product->getId();
    }

    /**
     * @param $attributeCode
     * @return mixed
     */
    protected function loadAttributeOptions($attributeCode){

        if(!isset($this->_attributeLabelToId[$attributeCode])){
            $result = [];

            $options = $this->_productAttributeRepository->get($attributeCode)->getOptions();
            /** @var \Magento\Eav\Api\Data\AttributeInterface $option */
            foreach($options as $option){
                $result[$option->getlabel()] = $option->getValue();
            }

            $this->_attributeLabelToId[$attributeCode] = $result;
        }

        return $this->_attributeLabelToId[$attributeCode];
    }

    /**
     * @param $attributeCode
     * @param $label
     * @return bool
     */
    protected function getAttributeOptionIdByLabel($attributeCode, $label){
        $options = $this->_attributeLabelToId[$attributeCode];

        return isset($options[$label])? $options[$label] : false;
    }

    /**
     * @param InputInterface $input
     * @return array|string
     */
    protected function loadFileData(InputInterface $input){
        $fileName = $input->getArgument(self::INPUT_KEY_FILENAME);

        if(empty($fileName))
            $fileName = self::DEFAULT_FILENAME;

        try{
            $result = $this->_csvProcessor->getData($this->_directoryList->getPath('var') . '/import/' . $fileName);
        }catch (\Exception $e){
            $result = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param $headers
     * @return array
     */
    protected function validateHeaders($headers){

        $errors = [];

        if(is_null($headers) || !is_array($headers)){
            return ['File format is invalid'];
        }

        foreach($this->_fields as $field){

            $index = array_search($field, $headers);

            if($index === false){
                $errors[] = 'The ' . $field . ' column is missing';
            }else{
                $this->_headersIndex[$field] = $index;
            }
        }

        for($i=0;$i<=8;$i++){
            $index = array_search($i, $headers);
            if($index === false){
                $errors[] = 'The Qty' . $i . ' column is missing';
            }else{
                $this->_headersIndex[self::Qty . $i] = $index;
            }
        }

        return $errors;
    }

    /**
     * @param $message
     * @return $this
     */
    protected function showError($message){
        $this->_output->writeln('<error>' . $message .'</error>');
        return $this;
    }

    /**
     * @param $message
     * @return $this
     */
    protected function showSuccess($message){
        $this->_output->writeln('<info>' . $message .'</info>');
        return $this;
    }
}