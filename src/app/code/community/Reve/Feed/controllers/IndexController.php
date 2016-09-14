<?php
class Reve_Feed_IndexController extends Mage_Core_Controller_Front_Action
{
	protected function _getHelper()
	{
		return Mage::helper('revefeed');
	}

	protected function _getDefaultStoreId()
	{
		return Mage::app()->getWebsite()->getDefaultGroup()->getDefaultStoreId();
	}

	public function indexAction()
	{
		$helper = $this->_getHelper();
		$reveKey = $helper->getReveKey();

		if ($reveKey != $_GET['key']) {
			$response = ['status' => 'NO ACCESS'];
			$this->writeResponse($response, 403);
			return;
		}

		if ($_GET['info'] == '1') {
			$this->infoResponse();
			return;
		}

		$this->feedReponse();
		return;
	}

	public function feedReponse()
	{
		$response = ['status' => 'SUCCESS'];
		$helper = $this->_getHelper();

		$reponse = ['status' => 'render'];

		$sizeAttrNames = $helper->getAttrNames();
		$storeID = isset($_GET['store']) ? $_GET['store'] : $this->_getDefaultStoreId();
		$currency = isset($_GET['currency']) ? $_GET['currency'] : null;
		$baseCurrencyCode = Mage::app()->getStore($storeID)->getBaseCurrencyCode();

		// render to a file and then output it's content
		$_filename = 'reve_feed.json';
		$_pidFile = '/tmp/reve_feed_export.pid';
		if ($_GET['force'] == 'true') unlink($_pidFile);

		if (file_exists($_pidFile)) {
			$response['status'] = 'Process already running! (use &force=true)';
			$this->writeResponse($response, 400);
			return;
		} else {
			touch($_pidFile);
		}

		$file = new Varien_Io_File();
		$path = Mage::getBaseDir('var') . DS . 'export' . DS;
		$filename = $path . DS . $_filename;
		$file->setAllowCreateFolders(true);
		$file->open(array('path' => $path));
		$file->streamOpen($filename, 'w');
		$file->streamLock(true);

		$lastProductId = 0;
		$batchSize = 100;
		do {
			try {
				$products = $helper->getProducts(
					$batchSize, $storeID, $currency, $baseCurrencyCode,
					$lastProductId, $sizeAttrNames
				);
				if ($products !== false) {
					$lastProductId = end($products)['id'];
					$file->streamWrite(json_encode($products));
				}
			} catch (Exception $ex) {
				unlink($_pidFile);
				die($ex->getMessage());
			}
		} while( $products !== false );

		$file->streamUnlock();
		$file->streamClose();

		if (file_exists($filename)) {
			file_put_contents($filename, str_replace('][', ',', file_get_contents($filename)));

			header('Content-Type: application/json');
			readfile($filename);
		}
		unlink($_pidFile); // the end of process
	}

	public function infoResponse()
	{
		$response = ['status' => 'SUCCESS'];
		$helper = $this->_getHelper();

		// list attributes
		$attributes = Mage::getModel('catalog/product')->getAttributes();
		$attributeArray = array();
		foreach($attributes as $a){
			foreach ($a->getEntityType()->getAttributeCodes() as $attributeName) {
				array_push($attributeArray, $attributeName);
			}
			break;
		}
		$response['attributes'] = $attributeArray;
		$sizeAttrNames = $helper->getAttrNames();
		$response['attribute_config'] = $sizeAttrNames;

		// list websites
		$websites = array();
		foreach (Mage::app()->getWebsites() as $website) {
			foreach ($website->getGroups() as $group) {
				$stores = $group->getStores();
				foreach ($stores as $store) {
					$storeID = $store->getStoreId();
					$countries = array();
					$countryList = Mage::getModel('directory/country')->getResourceCollection()
						->loadByStore($storeID)
						->toOptionArray(true);
					foreach ($countryList as $k => $country) {
						array_push($countries, $country['value']);
					}

					array_push($websites, [
						'id' => $storeID,
						'name' => $store->getName(),
						'currency' => $store->getCurrentCurrencyCode(),
						'countries' => $countries
					]);
				}
			}
		}
		$response['websites'] = $websites;

		// fetch a product
		$storeID = isset($_GET['store']) ? $_GET['store'] : $this->_getDefaultStoreId();
		$baseCurrencyCode = Mage::app()->getStore($storeID)->getBaseCurrencyCode();
		$response['product'] = $helper->getProducts(
			2, $storeID, $baseCurrencyCode, $baseCurrencyCode, 0, $sizeAttrNames
		);

		$this->writeResponse($response);
	}

	private function writeResponse($response, $statusCode = 200) {
		$this->getResponse()
			->clearHeaders()
			->setHeader('HTTP/1.0', $statusCode, true)
			->setHeader('Content-Type', 'application/json')
			->setBody(Mage::helper('core')
			->jsonEncode($response));
	}
}
