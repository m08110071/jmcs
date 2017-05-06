<?php

namespace ProSpecs\Catalog\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\App\State as AppState;

class ProductImageMigrateCommand extends Command
{
    /**
     * Media gallery attribute code.
     */
    const MEDIA_GALLERY_ATTRIBUTE_CODE = 'media_gallery';

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

    protected $_imagePath;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModelFactory
     */
    protected $_resourceFactory;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModel
     */
    protected $_resource;

    /**
     * DB connection.
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * @var Product\SkuProcessor
     */
    protected $skuProcessor;

    /**
     * @var string
     */
    protected $mediaGalleryTableName;

    /**
     * @var string
     */
    protected $mediaGalleryValueTableName;
    /**
     * @var string
     */
    protected $mediaGalleryEntityToValueTableName;

    /**
     * @var string
     */
    protected $productEntityTableName;

    /**
     * Media files uploader
     *
     * @var \Magento\CatalogImportExport\Model\Import\Uploader
     */
    protected $_fileUploader;


    /**
     * @var \Magento\CatalogImportExport\Model\Import\UploaderFactory
     */
    protected $_uploaderFactory;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $_mediaDirectory;

    /**
     * Attribute id for product images storage.
     *
     * @var array
     */
    protected $_mediaGalleryAttributeId = null;

    protected $_productAction;

    public function __construct(
        AppState $appState,
        \Magento\Framework\File\Csv $csvProcessor,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \Magento\Catalog\Model\ResourceModel\Product\Link $productLinkResource,
        \Magento\Catalog\Model\Product\Attribute\Repository $productAttributeRepository,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Catalog\Model\Product $product,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attributeFactory,
        \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModelFactory $resourceFactory,
        \Magento\CatalogImportExport\Model\Import\Product\SkuProcessor $skuProcessor,
        \Magento\CatalogImportExport\Model\Import\UploaderFactory $uploaderFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Model\ResourceModel\Product\Action $productAction,
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

        $this->_productAction = $productAction;

        $this->_resourceFactory = $resourceFactory;
        $this->_productModel = $product;
        $this->_connection = $product->getResource()->getConnection();
        $this->skuProcessor = $skuProcessor;
        $this->_uploaderFactory = $uploaderFactory;
        $this->_mediaDirectory = $filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::ROOT);;

        $this->_imagePath = 'var/import/image';

        parent::__construct($name);
    }

    /**
     * Get list of options and arguments for the command
     *
     * @return mixed
     */
    public function getInputList()
    {
        return [];
    }

    protected function configure()
    {
        $this->setName('catalog:product-image:migrate')
            ->setDescription('Migrate product image')
            ->setDefinition($this->getInputList());
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output){

        $this->appState->setAreaCode('catalog');

        $this->_output = $output;

        $output->writeln('<info>Starting To Migrate Product Image</info>');

        $existingImages = [];
        $mediaGallery = [];

        if ($handle = opendir($this->_imagePath)) {

            while (false !== ($entry = readdir($handle))) {

                if ($entry != "." && $entry != "..") {

                    $baseName = basename($this->_imagePath . '/' . $entry, '.png');

                    /** @var \Magento\Catalog\Model\Product $product */
                    $product = $this->getProductByUpc($baseName);

                    if($product->getId()){
                        $uploadedFile = $this->uploadMediaFiles(trim($entry), true);

                        $imageNotAssigned = !isset($existingImages[$uploadedFile]);

                        if ($uploadedFile && $imageNotAssigned) {
                            $mediaGallery[$product->getId()] = [
                                'attribute_id' => $this->getMediaGalleryAttributeId(),
                                'label' => $product->getName(),
                                'position' => 0,
                                'disabled' => 0,
                                'value' => $uploadedFile,
                            ];
                            $existingImages[$uploadedFile] = true;
                        }else{
                            $this->showError('['  . $entry . '] Upload error');
                        }

                        if(count($mediaGallery) >= 1000){
                            $this->_saveMediaGallery($mediaGallery);
                            $mediaGallery = [];
                        }

                    }else{
                        $this->showError('[' . $baseName . '] UPC do not exist');
                    }
                }
            }

            $this->_saveMediaGallery($mediaGallery);

            closedir($handle);

            $this->showSuccess('Complete!!!!!!!!!!!!!!!!!!!!!!!!!!');
        }
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
     * Init media gallery resources
     * @return void
     */
    protected function initMediaGalleryResources()
    {
        if (null == $this->productEntityTableName) {
            $this->productEntityTableName = $this->getResource()->getTable('catalog_product_entity');
            $this->mediaGalleryTableName = $this->getResource()->getTable('catalog_product_entity_media_gallery');
            $this->mediaGalleryValueTableName = $this->getResource()->getTable(
                'catalog_product_entity_media_gallery_value'
            );
            $this->mediaGalleryEntityToValueTableName = $this->getResource()->getTable(
                'catalog_product_entity_media_gallery_value_to_entity'
            );
        }
    }

    /**
     * Save product media gallery.
     *
     * @param array $mediaGalleryData
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _saveMediaGallery(array $mediaGalleryData)
    {
        if (empty($mediaGalleryData)) {
            return $this;
        }
        $this->initMediaGalleryResources();
        $productIds = [];
        $imageNames = [];
        $multiInsertData = [];
        $valueToProductId = [];
        foreach ($mediaGalleryData as $productId => $insertValue) {
            $productIds[] = $productId;

            $valueArr = [
                'attribute_id' => $insertValue['attribute_id'],
                'value' => $insertValue['value'],
            ];
            $valueToProductId[$insertValue['value']][] = $productId;
            $imageNames[] = $insertValue['value'];
            $multiInsertData[] = $valueArr;
        }
        $oldMediaValues = $this->_connection->fetchAssoc(
            $this->_connection->select()->from($this->mediaGalleryTableName, ['value_id', 'value'])
                ->where('value IN (?)', $imageNames)
        );
        $this->_connection->insertOnDuplicate($this->mediaGalleryTableName, $multiInsertData, []);
        $multiInsertData = [];
        $newMediaSelect = $this->_connection->select()->from($this->mediaGalleryTableName, ['value_id', 'value'])
            ->where('value IN (?)', $imageNames);
        if (array_keys($oldMediaValues)) {
            $newMediaSelect->where('value_id NOT IN (?)', array_keys($oldMediaValues));
        }

        $dataForSkinnyTable = [];
        $newMediaValues = $this->_connection->fetchAssoc($newMediaSelect);
        foreach ($mediaGalleryData as $productId => $insertValue) {
            foreach ($newMediaValues as $value_id => $values) {
                if ($values['value'] == $insertValue['value']) {
                    $insertValue['value_id'] = $value_id;
                    $insertValue['entity_id'] = array_shift($valueToProductId[$values['value']]);
                    unset($newMediaValues[$value_id]);
                    break;
                }
            }
            if (isset($insertValue['value_id'])) {
                $valueArr = [
                    'value_id' => $insertValue['value_id'],
                    'store_id' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
                    'entity_id' => $insertValue['entity_id'],
                    'label' => $insertValue['label'],
                    'position' => $insertValue['position'],
                    'disabled' => $insertValue['disabled'],
                ];
                $multiInsertData[] = $valueArr;
                $dataForSkinnyTable[] = [
                    'value_id' => $insertValue['value_id'],
                    'entity_id' => $insertValue['entity_id'],
                ];
            }
        }
        try {
            $this->_connection->insertOnDuplicate(
                $this->mediaGalleryValueTableName,
                $multiInsertData,
                ['value_id', 'store_id', 'entity_id', 'label', 'position', 'disabled']
            );
            $this->_connection->insertOnDuplicate(
                $this->mediaGalleryEntityToValueTableName,
                $dataForSkinnyTable,
                ['value_id']
            );
        } catch (\Exception $e) {
            $this->_connection->delete(
                $this->mediaGalleryTableName,
                $this->_connection->quoteInto('value_id IN (?)', $newMediaValues)
            );

            $this->showError('[ERROR]' . $e->getMessage());
        }

        $this->updateProductMediaAttribute($mediaGalleryData);

        return $this;
    }


    /**
     * @param $mediaGalleryData
     * @throws \Exception
     */
    protected function updateProductMediaAttribute($mediaGalleryData){
        foreach($mediaGalleryData as $productId =>  $mediaData){
            try{
                $this->_productAction->updateAttributes(
                    [$productId],
                    [
                        'image' => $mediaData['value'],
                        'small_image' => $mediaData['value'],
                        'thumbnail' => $mediaData['value']
                    ],
                    0
                );
            }catch (\Exception $e){
                $this->showError('[' . $productId . '][Set attribute]' . $e->getMessage());
            }
        }
    }

    /**
     * Uploading files into the "catalog/product" media folder.
     * Return a new file name if the same file is already exists.
     *
     * @param string $fileName
     * @return string
     */
    protected function uploadMediaFiles($fileName, $renameFileOff = false)
    {
        try {
            $res = $this->_getUploader()->move($fileName, $renameFileOff);
            return $res['file'];
        } catch (\Exception $e) {
            var_dump($e->getMessage());
            return '';
        }
    }

    /**
     * Returns an object for upload a media files
     *
     * @return \Magento\CatalogImportExport\Model\Import\Uploader
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getUploader()
    {
        if (is_null($this->_fileUploader)) {
            $this->_fileUploader = $this->_uploaderFactory->create();

            $this->_fileUploader->init();

            $dirConfig = DirectoryList::getDefaultConfig();
            $dirAddon = $dirConfig[DirectoryList::MEDIA][DirectoryList::PATH];

            $DS = DIRECTORY_SEPARATOR;

            $tmpPath = $this->_imagePath;

            if (!$this->_fileUploader->setTmpDir($tmpPath)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('File directory \'%1\' is not readable.', $tmpPath)
                );
            }
            $destinationDir = "catalog/product";
            $destinationPath = $dirAddon . $DS . $this->_mediaDirectory->getRelativePath($destinationDir);

            $this->_mediaDirectory->create($destinationPath);
            if (!$this->_fileUploader->setDestDir($destinationPath)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('File directory \'%1\' is not writable.', $destinationPath)
                );
            }
        }
        return $this->_fileUploader;
    }

    /**
     * Retrieve id of media gallery attribute.
     *
     * @return int
     */
    public function getMediaGalleryAttributeId()
    {
        if (!$this->_mediaGalleryAttributeId) {
            /** @var $resource \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceModel */
            $resource = $this->_resourceFactory->create();
            $this->_mediaGalleryAttributeId = $resource->getAttribute(self::MEDIA_GALLERY_ATTRIBUTE_CODE)->getId();
        }
        return $this->_mediaGalleryAttributeId;
    }

    /**
     * @param $upc
     * @return \Magento\Framework\DataObject
     */
    protected function getProductByUpc($upc){
        $collection = $this->_productModel->getCollection();
        $collection->addAttributeToFilter('upc', $upc);
        $collection->setPageSize(1);

        return $collection->getFirstItem();
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