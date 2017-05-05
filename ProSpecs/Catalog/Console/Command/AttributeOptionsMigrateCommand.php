<?php

namespace ProSpecs\Catalog\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\App\State as AppState;

class AttributeOptionsMigrateCommand extends Command
{
    const LINK_TYPE = 'related';

    const INPUT_KEY_FILENAME = 'file_name';

    const DEFAULT_FILENAME = 'RELATED_COMMODITY_A.csv';

    const BRAND = 'Brand/Label/Publisher';

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

    protected $_eavSetupFactory;
    protected $_storeManager;
    protected $_attributeFactory;

    protected $_options = [];



    protected $_attributeLabelToId = [];

    protected $_headersIndex = [];

    protected $_fields = [
        self::BRAND
    ];

    public function __construct(
        AppState $appState,
        \Magento\Framework\File\Csv $csvProcessor,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Catalog\Model\ResourceModel\Product\Link $productLinkResource,
        \Magento\Catalog\Model\Product\Attribute\Repository $productAttributeRepository,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attributeFactory,
        $name = null
    ){
        $this->appState = $appState;
        $this->_csvProcessor = $csvProcessor;
        $this->_directoryList = $directoryList;
        $this->_productRepository = $productRepositoryInterface;
        $this->_productLinkResource = $productLinkResource;
        $this->_productAttributeRepository = $productAttributeRepository;
        $this->_eavSetupFactory = $eavSetupFactory;
        $this->_storeManager = $storeManager;
        $this->_attributeFactory = $attributeFactory;

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
        $this->setName('catalog:product-attribute-option:migrate')
            ->setDescription('Migrate product attribute option')
            ->setDefinition($this->getInputList());
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output){

        $this->appState->setAreaCode('catalog');

        $this->_output = $output;

        $output->writeln('<info>Starting To Migrate Product Attribute Options</info>');

        $migrateData = $this->loadFileData($input);

        if(is_array($migrateData)){
            $headers = array_shift($migrateData);

            $headerErrors = $this->validateHeaders($headers);

            if(count($headerErrors)){
                foreach($headerErrors as $error){
                    $output->writeln('<error>'. $error .'</error>');
                }
            }else{

                $this->_options = array_keys($this->loadAttributeOptions('manufacturer'));

                foreach($migrateData as $rowNum =>  $rowData){

                    $brandIndex = $this->_headersIndex[self::BRAND];

                    if(
                        $rowData[$brandIndex] &&
                        !in_array($rowData[$brandIndex], $this->_options)
                    ){
                        $this->_options[] = $rowData[$brandIndex];
                    }
                }

                $attributeInfo=$this->_attributeFactory->getCollection()
                    ->addFieldToFilter('attribute_code',['eq'=>"manufacturer"])
                    ->getFirstItem();
                $attribute_id = $attributeInfo->getAttributeId();

                $option=array();
                $option['attribute_id'] = $attributeInfo->getAttributeId();
                foreach($this->_options as $key=>$value){
                    $option['value'][$value][0]=$value;
                    foreach($this->_storeManager->getStores() as $store){
                        $option['value'][$value][$store->getId()] = $value;
                    }
                }

                $eavSetup = $this->_eavSetupFactory->create();
                $eavSetup->addAttributeOption($option);
            }
        }else{
            $output->writeln('<error>'. $migrateData .'</error>');
        }

        $output->writeln('<info>The Product Migration Process Has Finished</info>');
    }

    /**
     * @param $attributeCode
     * @return mixed
     */
    protected function loadAttributeOptions($attributeCode){

        if(!isset($this->_attributeLabelToId[$attributeCode])){
            $result = [];

            $options = $this->_productAttributeRepository->get($attributeCode)->getOptions();
            /** @var \Magento\Eav\Api\Data\AttributeOptionInterface $option */
            foreach($options as $option){
                $result[$option->getLabel()] = $option->getValue();
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