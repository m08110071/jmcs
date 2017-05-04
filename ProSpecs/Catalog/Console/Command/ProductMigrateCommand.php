<?php

namespace ProSpecs\Catalog\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\App\State as AppState;

class ProductMigrateCommand extends Command
{
    const LINK_TYPE = 'related';

    const INPUT_KEY_FILENAME = 'file_name';

    const DEFAULT_FILENAME = 'RELATED_COMMODITY_A.csv';

    const SKU = 'SKU';
    const NAME = 'TITLE';
    const BRAND = 'BRAND';
    const CATEGORY = 'CATEGORY';
    const UPC = 'UPC';

    /**
     * @var AppState
     */
    protected $appState;

    protected $_csvProcessor;

    protected $_directoryList;

    protected $_productLinkResource;

    protected $_productRepository;

    protected $_productAttributeRepository;



    protected $_attributeLabelToId = [];

    protected $_headersIndex = [];

    protected $_fields = [
        self::SKU,
        self::NAME,
        self::BRAND,
        self::CATEGORY,
        self::UPC
    ];

    public function __construct(
        AppState $appState,
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
        $this->setName('catalog:product:migrate')
            ->setDescription('Migrate product')
            ->setDefinition($this->getInputList());
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output){

        $this->appState->setAreaCode('catalog');

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

                    }
                }

                $output->writeln('<info>Starting To Save Product Data</info>');
                $this->saveLinks($output);
            }
        }else{
            $output->writeln('<error>'. $migrateData .'</error>');
        }

        $output->writeln('<info>The Product Migration Process Has Finished</info>');
    }

    protected function saveProduct($rowData){

    }

    public function prepareData($rowData){
        $result = [];
    }

    /**
     * @param $rowData
     * @param $rowNum
     * @param OutputInterface $output
     * @return bool
     */
    protected function isValidData($rowData, $rowNum, OutputInterface $output){
        $errors = $this->validateRowData($rowData);

        if(count($errors)){
            foreach($errors as $error){
                $output->writeln('<error>[' . $rowNum . '] ' .$error .'</error>');
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
        $nameIndex = $this->_headersIndex[self::NAME];
        $brandIndex = $this->_headersIndex[self::BRAND];
        $categoryIndex = $this->_headersIndex[self::CATEGORY];
        $upcIndex = $this->_headersIndex[self::UPC];

        foreach($this->_headersIndex as $index){
            if(!isset($rowData[$index]))
                return ['Data is invalid'];
        }

        if($rowData[$skuIndex] == ''){
            $errors[] = 'Column: ' . self::SKU . ', is required field';
        }

        if($rowData[$nameIndex] == ''){
            $errors[] = 'Column: ' . self::NAME . ', is required field';
        }

        if($rowData[$brandIndex] == ''){
            $errors[] = 'Column: ' . self::BRAND . ', is required field';
        }else{
            if(!$this->getAttributeOptionIdByLabel('', $rowData[$brandIndex])){
                $errors[] = 'Column: ' . self::BRAND . ', value "' . $rowData[$brandIndex] . '" is invalid';
            }
        }

        if($rowData[$upcIndex] == ''){
            $errors[] = 'Column: ' . self::UPC . ', is required field';
        }

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

            $options = $this->_productAttributeRepository->get($attributeCode);
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

        return $errors;
    }
}