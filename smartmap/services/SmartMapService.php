<?php
namespace Craft;

class SmartMapService extends BaseApplicationComponent
{

	public $settings;

	public $here;
	public $geoInfoSet = false;
	private $_ipCookieName = 'smartMap_myIp';

	public $cookieData = false;
	public $cacheData = false;

	public $targetCoords; // TEMP: Until P&T "distance" fix

	public $measurementUnit;
	public $mapApi;
	public $mapApiKey;

	public $defaultZoom = 11;

	function init()
	{
		parent::init();
		$this->here = array( // Default to empty container array
			'ip'           => false,
			'country_code' => false,
			'country_name' => false,
			'region_code'  => false,
			'region_name'  => false,
			'city'         => false,
			'zipcode'      => false,
			'latitude'     => false,
			'longitude'    => false,
			'metro_code'   => false,
			'area_code'    => false,
		);
		
		if (!craft()->isConsole()) {
			$ipCookie = $this->_ipCookieName;
			if (array_key_exists($ipCookie, $_COOKIE)) {
				$this->cookieData = json_decode($_COOKIE[$ipCookie], true);
			}
			$this->currentLocation();
		}
	}

	// Automatically detect & set current location
	public function currentLocation()
	{

		$ip = $this->_detectMyIp();

		if (!$ip) {
			if ($this->cookieData) {
				$ip = $this->cookieData['ip'];
			} else {
				$this->_setGeoData();
			}
		}

		if ($ip && !$this->geoInfoSet) {
			$this->_setGeoData($ip);
		}

	}

	// 
	private function _detectMyIp()
	{
		$ip = $_SERVER['REMOTE_ADDR'];
		$ipPattern = '/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/';
		if (('127.0.0.1' == $ip) || (!preg_match($ipPattern, $ip))) {
			return false;
		} else {
			return $ip;
		}
	}

	// 
	private function _setGeoData($ip = '')
	{
		if (!$this->_matchGeoData($ip)) {
			try
			{
				$client = new \Guzzle\Http\Client('http://freegeoip.net');
				$this->here = $client->get('/json/'.$ip)->send()->json();
				if (!$ip) {
					$ip = $this->here['ip'];
					$cookieExpires = time()+(60*5); // Expires in five minutes
					$this->cookieData = array(
						'ip'      => $ip,
						'expires' => $cookieExpires,
					);
					setcookie($this->_ipCookieName, json_encode($this->cookieData), $cookieExpires, '/');
				}
				$this->_cacheGeoData($ip);
			}
			catch (\Exception $e)
			{
				$message = 'The request to freegeoip.net failed: '.$e->getMessage();
				Craft::log($message, LogLevel::Warning);
			}
		}
		$this->geoInfoSet = true;
	}

	// Retrieve cached geo information for IP address
	private function _matchGeoData($ip)
	{
		if ($ip) {
			$this->cacheData = craft()->fileCache->get($ip);
			if ($this->cacheData) {
				$this->here = $this->cacheData['here'];
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	// Cache geo information for IP address
	private function _cacheGeoData($ip, $lifespan = 7776000) // 60*60*24*90 // Expires in 90 days
	{
		if ($ip) {
			$data = array(
				'here'    => $this->here,
				'expires' => time() + $lifespan,
			);
			craft()->fileCache->set($ip, $data, $lifespan);
			$this->cacheData = $data;
		}
	}

	// ==================================================== //

	// TEMP: Until P&T "distance" fix
	// Use haversine formula
	private function _haversinePHP($coords_1, $coords_2)
	{
		// Determine unit of measurement
		switch ($this->measurementUnit) {
			case MeasurementUnit::Kilometers:
				$unitVal = 6371;
				break;
			default:
			case MeasurementUnit::Miles:
				$unitVal = 3959;
				break;
		}
		// Set coordinates
		$lat_1 = $coords_1['lat'];
		$lng_1 = $coords_1['lng'];
		$lat_2 = $coords_2['lat'];
		$lng_2 = $coords_2['lng'];
		// Calculate haversine formula
		return ($unitVal * acos(cos(deg2rad($lat_1)) * cos(deg2rad($lat_2)) * cos(deg2rad($lng_2) - deg2rad($lng_1)) + sin(deg2rad($lat_1)) * sin(deg2rad($lat_2))));
	}
	// END TEMP

	// Check if API key is valid
	public function checkApiKey()
	{
		if (!$this->mapApiKey) {
			throw new Exception("Please enter your Google Maps API key. [/admin/settings/plugins/smartmap]");
		}
	}


	// ==================================================== //
	// CALLED VIA SmartMap_AddressFieldType::modifyElementsQuery()
	// ==================================================== //

	// Modify fieldtype query
	public function modifyQuery(DbCommand $query, $params = array())
	{
		// Join with plugin table
		$query->join(SmartMap_AddressRecord::TABLE_NAME, 'elements.id='.craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId');
		// Search by comparing coordinates
		$this->_searchCoords($query, $params);
	}


	// ==================================================== //
	// CALLED VIA FIELDTYPE
	// ==================================================== //

	// Save field to plugin table
	public function saveAddressField(BaseFieldType $fieldType)
	{
		// Get handle, elementId, and content
		$handle    = $fieldType->model->handle;
		$elementId = $fieldType->element->id;
		$content   = $fieldType->element->getContent();

		// Set specified attributes
		$attr = $content->getAttribute($handle);

		// Return false if attribute doesn't exist
		if (!$attr) {
			return false;
		}

		// Attempt to load existing record
		$addressRecord = SmartMap_AddressRecord::model()->findByAttributes(array(
			'elementId' => $elementId,
			'handle'    => $handle,
		));

		// If no record exists, create new record
		if (!$addressRecord) {
			$addressRecord = new SmartMap_AddressRecord;
			$attr['elementId'] = $elementId;
			$attr['handle']    = $handle;
		}

		// Force empty values as NULL
		if (!$attr['street1']) {$attr['street1'] = null;}
		if (!$attr['street2']) {$attr['street2'] = null;}
		if (!$attr['city'])    {$attr['city']    = null;}
		if (!$attr['state'])   {$attr['state']   = null;}
		if (!$attr['zip'])     {$attr['zip']     = null;}
		if (!$attr['country']) {$attr['country'] = null;}
		if (!$attr['lat'])     {$attr['lat']     = null;}
		if (!$attr['lng'])     {$attr['lng']     = null;}

		// Set record attributes
		$addressRecord->setAttributes($attr, false);

		//if (!$addressRecord->save()) {
		//    $errors = $addressRecord->getErrors();
		//}

		return $addressRecord->save();

	}

	// Retrieves address from 3rd party table
	public function getAddress(BaseFieldType $fieldType)
	{
		// Load record (if exists)
		$addressRecord = SmartMap_AddressRecord::model()->findByAttributes(array(
			'elementId' => $fieldType->element->id,
			'handle'    => $fieldType->model->handle,
		));

		// Get attributes
		if ($addressRecord) {
			$attr = $addressRecord->getAttributes();
			$attr['distance'] = $this->_haversinePHP($this->targetCoords, $attr); // TEMP: Until P&T "distance" fix
		} else {
			$attr = array();
		}

		return $attr;
	}

	// ==================================================== //
	// PRIVATE METHODS
	// ==================================================== //

	// Parse query filter
	private function _parseFilter($params = array())
	{

		if (!is_array($params)) {
			$params = array();
			$api = MapApi::LatLngArray;
			$coords = $this->_defaultCoords();
		} else if (!array_key_exists('target', $params)) {
			$api = MapApi::LatLngArray;
			$coords = $this->_defaultCoords();
		} else if (is_array($params['target'])) {
			$api = MapApi::LatLngArray;
			if (!$this->isAssoc($params['target']) && count($params['target']) == 2) {
				$lat = $params['target'][0];
				$lng = $params['target'][1];
			} else {
				$lat = $this->findKeyInArray($params['target'],array('latitude','lat'));
				$lng = $this->findKeyInArray($params['target'],array('longitude','lng','lon','long'));
			}
			$coords = array(
				'lat' => $lat,
				'lng' => $lng,
			);
		} else if (is_string($params['target']) || is_numeric($params['target'])) {
			$api = MapApi::GoogleMaps;
		} else {
			// Invalid target
			//  - Throw error here?
			$api = MapApi::LatLngArray;
			$coords = $this->_defaultCoords();
		}

		$filter = SmartMap_FilterCriteriaModel::populateModel($params);

		// If page is specified
		if ($filter->page) {
			$filter->offset = ($filter->page * $filter->limit) - $filter->limit;
		}

		switch ($api) {
			case MapApi::LatLngArray:
				$filter->coords = $coords;
				break;
			case MapApi::GoogleMaps:
			default:
				$filter->coords = $this->_geocodeGoogleMapApi($filter->target);
				break;
		}

		$this->targetCoords    = $filter->coords; // TEMP: Until P&T "distance" fix
		$this->measurementUnit = $filter->units;  // TEMP: Until P&T "distance" fix

		return $filter;
	}

	// Search by coordinates
	private function _searchCoords(&$query, $params = array())
	{
		$filter = $this->_parseFilter($params);
		// Implement haversine formula
		$haversine = $this->_haversine(
			$filter->coords['lat'],
			$filter->coords['lng'],
			$filter->units
		);
		// Modify query
		$query
			->addSelect($haversine.' AS distance')
			->having('distance <= '.$filter->range)
		;
	}

	// Use haversine formula
	private function _haversine($lat, $lng)
	{
		// Determine unit of measurement
		switch ($this->measurementUnit) {
			case MeasurementUnit::Kilometers:
				$unitVal = 6371;
				break;
			default:
			case MeasurementUnit::Miles:
				$unitVal = 3959;
				break;
		}
		// Set table reference
		$table = craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME;
		// Calculate haversine formula
		return "($unitVal * acos(cos(radians($lat)) * cos(radians($table.lat)) * cos(radians($table.lng) - radians($lng)) + sin(radians($lat)) * sin(radians($table.lat))))";
	}

	// Get coordinates from Google Maps API
	private function _geocodeGoogleMapApi($target)
	{

		$api = 'http://maps.googleapis.com/maps/api/geocode/json?address='.rawurlencode($target).'&sensor=false';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = json_decode(curl_exec($ch), true);

		if (empty($response['results'])) {
			return $this->_defaultCoords();
		} else {
			return $response['results'][0]['geometry']['location'];
		}

	}

	// Decipher map center & markers based on locations
	public function markerCoords($locations, $options = array())
	{

		if (!$locations || empty($locations)) {
			return array(
				'center'  => $this->_defaultCoords(),
				'markers' => array(),
			);
		}

		// If one location, process as an array
		if ($locations && !is_array($locations)) {
			return $this->markerCoords(array($locations), $options);
		}

		// If ElementCriteriaModel, convert to normal array
		if (is_object($locations[0]) && is_a($locations[0], 'Craft\\ElementCriteriaModel')) {
			return $this->markerCoords($locations[0]->find(), $options);
		}

		// Initialize variables
		$markers = array();
		$allLats = array();
		$allLngs = array();
		$handles = array();

		// If locations are specified
		if (!empty($locations)) {
			// If location is a pair of coordinates
			if (!craft()->smartMap->isAssoc($locations) && count($locations) == 2 && !is_object($locations[0])) {
				$lat = $locations[0];
				$lng = $locations[1];
				$allLats[] = $lat;
				$allLngs[] = $lng;
				$markers[] = array(
					'lat' => $lat,
					'lng' => $lng,
				);
			} else {
				// Find all Smart Map Address field handles
				foreach (craft()->fields->getAllFields() as $field) {
					if ($field->type == 'SmartMap_Address') {
						$handles[] = $field->handle;
					}
				}
				// Loop through locations
				foreach ($locations as $loc) {
					if (is_object($loc)) {
						// If location is an object
						if (!empty($handles)) {
							foreach ($handles as $handle) {
								if (isset($loc->{$handle})) {
									$address = $loc->{$handle};
									if (!empty($address)) {
										$lat = $address['lat'];
										$lng = $address['lng'];
										$markers[] = array(
											'lat'     => (float) $lat,
											'lng'     => (float) $lng,
											'title'   => $loc->title,
											'element' => $loc
										);
										$allLats[] = $lat;
										$allLngs[] = $lng;
									}
								}
							}
						}
					} else if (is_array($loc)) {
						// Else, if location is an array
						if (!craft()->smartMap->isAssoc($loc) && count($loc) == 2 && !is_object($loc[0])) {
							$lat = $loc[0];
							$lng = $loc[1];
							$title = '';
						} else {
							$lat = craft()->smartMap->findKeyInArray($loc, array('latitude','lat'));
							$lng = craft()->smartMap->findKeyInArray($loc, array('longitude','lng','lon','long'));
							$title = (array_key_exists('title',$loc) ? $loc['title'] : '');
						}
						$markers[] = array(
							'lat'     => $lat,
							'lng'     => $lng,
							'title'   => $title,
							'element' => $loc
						);
						$allLats[] = $lat;
						$allLngs[] = $lng;
					}
				}
			}
		}

		// Determine center of map
		if (array_key_exists('center', $options)) {
			// Center is specified in options
			$center = $options['center'];
		} else if (empty($locations) || empty($allLats) || empty($allLngs)) {
			// Error was triggered
			$markers = array();
			if (array_key_exists('target', $options)) {
				$center = $this->targetCoords = $this->_geocodeGoogleMapApi($options['target']);
			} else {
				$center = $this->targetCenter();
			}
		} else {
			// Calculate center of map
			$centerLat = (min($allLats) + max($allLats)) / 2;
			$centerLng = (min($allLngs) + max($allLngs)) / 2;
			$center = array(
				'lat' => round($centerLat, 6),
				'lng' => round($centerLng, 6)
			);
		}

		// Return center point and all markers
		return array(
			'center'  => $center,
			'markers' => $markers,
		);
	}

	// Search via AJAX
	public function ajaxSearch($params)
	{
		$query = craft()->db->createCommand()
			->select()
			->from('elements');

		// Join with plugin table
		$query->join(SmartMap_AddressRecord::TABLE_NAME, craft()->db->tablePrefix.'elements.id='.craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId');

		// Join with content table
		$query->join('content', craft()->db->tablePrefix.'elements.id='.craft()->db->tablePrefix.'content.elementId');

		// Set query limit
		if (array_key_exists('limit', $params)) {
			$query->limit($params['limit']);
		}

		// Filter by specified section(s)
		if (array_key_exists('section', $params)) {
			if (!is_array($params['section'])) {
				$where = craft()->db->tablePrefix.'sections.handle=:handle';
				$pdo = array(':handle'=>$params['section']);
			} else {
				$i = 0;
				$where = '';
				$pdo = array();
				foreach ($params['section'] as $handle) {
					if ($where) {$where .= ' OR ';}
					$where .= craft()->db->tablePrefix.'sections.handle=:handle'.$i;
					$pdo[':handle'.$i] = $handle;
					$i++;
				}
			}
			$query
				->join('entries', craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId='.craft()->db->tablePrefix.'entries.id')
				->join('sections', craft()->db->tablePrefix.'entries.sectionId='.craft()->db->tablePrefix.'sections.id')
				->andWhere($where, $pdo)
			;
		}

		/* BUG: Not working properly
		// Filter by specified field(s)
		if (array_key_exists('field', $params)) {
			if (!is_array($params['field'])) {
				$where = craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.handle=:handle';
				$pdo = array(':handle'=>$params['field']);
			} else {
				$i = 0;
				$where = '';
				$pdo = array();
				foreach ($params['field'] as $handle) {
					if ($where) {$where .= ' OR ';}
					$where .= craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.handle=:handle'.$i;
					$pdo[':handle'.$i] = $handle;
					$i++;
				}
			}
			$query
				->andWhere($where, $pdo)
			;
		}
		*/

		// Search by comparing coordinates
		$this->_searchCoords($query, $params);

		$query->order('distance');
		$markers = $query->queryAll();
		return $this->markerCoords($markers);
	}

	// Center coordinates of target
	public function targetCenter($target = false)
	{
		$coords =& $this->targetCoords;
		if (!$coords) {
			if ($target) {
				$coords = $this->_geocodeGoogleMapApi($target);
			} else {
				$coords = $this->_defaultCoords();
			}
		}
		return $coords;
	}

	// Use default coordinates
	public function _defaultCoords()
	{
		$defaultCoords = array(
			// Point Nemo
			'lat' => -48.876667,
			'lng' => -123.393333,
		);
		if (array_key_exists('latitude', $this->here) && array_key_exists('longitude', $this->here)) {
			$coords = array(
				// Current location
				'lat' => $this->here['latitude'],
				'lng' => $this->here['longitude'],
			);
		} else {
			$coords = $defaultCoords;
		}
		if (!$coords['lat'] && !$coords['lng']) {
			$coords = $defaultCoords;
		}
		return $coords;
	}


	// ==================================================== //
	// HELPER FUNCTIONS
	// ==================================================== //

	// Get the target from an array
	public function findKeyInArray($array, $possibleKeys)
	{
		foreach ($possibleKeys as $key) {
			if (array_key_exists($key, $array)) {
				return $array[$key];
			}
		}
	}

	// Determine if array is associative
	public function isAssoc($array) {
		return (bool) count(array_filter(array_keys($array), 'is_string'));
	}

}
