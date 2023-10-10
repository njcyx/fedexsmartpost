<?php

//if (IS_ADMIN_FLAG)
//error_reporting(0);

class fedexsmartpost {
public
    $code, 
    $title,
    $description,
    $icon,
    $enabled,
    $tax_class,
    $sort_order,
    $quotes;

protected
    $_check,
    $version,
    $fedex_key,
    $fedex_pwd,
    $fedex_act_num,
    $fedex_meter_num,
    $country,
    $fedex_shipping_num_boxes,
    $fedex_shipping_weight,
    $insurance;
	
	//Class Constructor
	//function fedexsmartpost() {
	function __construct() {
		global $order, $customer_id, $db;
	
		$this->code        = "fedexsmartpost";
		$this->title       = MODULE_SHIPPING_FEDEX_SMARTPOST_TEXT_TITLE;
		$this->description = MODULE_SHIPPING_FEDEX_SMARTPOST_TEXT_DESCRIPTION;
		$this->sort_order = (defined('MODULE_SHIPPING_FEDEX_SMARTPOST_SORT_ORDER')) ? (int)MODULE_SHIPPING_FEDEX_SMARTPOST_SORT_ORDER: null; //special thanks to @lat9 for warning fixes!
       	 	if ($this->sort_order === null) {
            		return false;
        	}
	//	$this->icon        = DIR_WS_IMAGES . 'fedex-images/SMARTPOST.gif';
		$this->tax_class   = MODULE_SHIPPING_FEDEX_SMARTPOST_TAX_CLASS;
		if (zen_get_shipping_enabled($this->code)) {
			$this->enabled = ((MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS == 'true') ? true : false);
		}
		$this->fedex_key       = MODULE_SHIPPING_FEDEX_SMARTPOST_KEY;
		$this->fedex_pwd       = MODULE_SHIPPING_FEDEX_SMARTPOST_PWD;
		$this->fedex_act_num   = MODULE_SHIPPING_FEDEX_SMARTPOST_ACT_NUM;
		$this->fedex_meter_num = MODULE_SHIPPING_FEDEX_SMARTPOST_METER_NUM;
		if (defined("SHIPPING_ORIGIN_COUNTRY")) {
			$countries_array = zen_get_countries(SHIPPING_ORIGIN_COUNTRY, true);
			$this->country   = $countries_array['countries_iso_code_2'];
		} else {
			$this->country = STORE_ORIGIN_COUNTRY;
		}
		if (($this->enabled == true) && ((int) MODULE_SHIPPING_FEDEX_SMARTPOST_ZONE > 0)) {
			$check_flag = false;
			$check      = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_SHIPPING_FEDEX_SMARTPOST_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
			while (!$check->EOF) {
				if ($check->fields['zone_id'] < 1) {
					$check_flag = true;
					break;
				} elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
					$check_flag = true;
					break;
				}
				$check->MoveNext();
			}
			
			if ($check_flag == false) {
				$this->enabled = false;
			}
		}
	}
	
	//Class Methods
	
	function quote($method = '') {
		/* FedEx integration starts */
		global $db, $shipping_weight, $shipping_num_boxes, $cart, $order;
				
		// shipping boxes manager
		if(!defined('MODULE_SHIPPING_BOXES_MANAGER_STATUS')){
        		define('MODULE_SHIPPING_BOXES_MANAGER_STATUS', 'false');
    		}
    		if (MODULE_SHIPPING_BOXES_MANAGER_STATUS == 'true') {
          		global $packed_boxes;    
    		}
		
		// Disable the plug-in for non-US quote. 
		if ($order->delivery['country']['iso_code_2'] != "US") {
     		   	$this->quotes = [];
        		return $this->quotes;
    		}
		
		require_once(DIR_WS_INCLUDES . 'library/fedex-common.php5');
		//$path_to_wsdl = DIR_WS_MODULES . "shipping/fedexsmartpost/wsdl/RateService_v10.wsdl";
		$path_to_wsdl = DIR_WS_MODULES . "shipping/fedexwebservices/wsdl/RateService_v31.wsdl";
		ini_set("soap.wsdl_cache_enabled", "0");
		$client = new SoapClient($path_to_wsdl, array(
			'trace' => 1
		));
		
		// customer details		  
		$street_address  = $order->delivery['street_address'];
		$street_address2 = $order->delivery['suburb'];
		$city            = $order->delivery['postcode'];
		$state           = zen_get_zone_code($order->delivery['country']['id'], $order->delivery['zone_id'], '');
		if ($state == "QC")
			$state = "PQ";
		$postcode   = str_replace(array(
			' ',
			'-'
		), '', $order->delivery['postcode']);
		$country_id = $order->delivery['country']['iso_code_2'];
		
		$totals = $order->info['subtotal'] = $_SESSION['cart']->show_total();
		$this->_setInsuranceValue($totals);
		
		$request['WebAuthenticationDetail']                         = array(
			'UserCredential' => array(
				'Key' => $this->fedex_key,
				'Password' => $this->fedex_pwd
			)
		); // Replace 'XXX' and 'YYY' with FedEx provided credentials 
		$request['ClientDetail']                                    = array(
			'AccountNumber' => $this->fedex_act_num,
			'MeterNumber' => $this->fedex_meter_num
		); // Replace 'XXX' with your account and meter number
		$request['TransactionDetail']                               = array(
		//	'CustomerTransactionId' => ' *** Rate Request v10 using PHP ***'
			'CustomerTransactionId' => ' *** Rate Request using PHP ***'
		);
	/*	$request['Version']                                         = array(
			'ServiceId' => 'crs',
			'Major' => '10',
			'Intermediate' => '0',
			'Minor' => '0'
		);*/
			$request['Version']                                         = array(
			'ServiceId' => 'crs',
			'Major' => '31',
			'Intermediate' => '0',
			'Minor' => '0'
		);
		$request['ReturnTransitAndCommit']                          = true;
		$request['RequestedShipment']['DropoffType']                = $this->_setDropOff(); // valid values REGULAR_PICKUP, REQUEST_COURIER, ...
		$request['RequestedShipment']['ShipTimestamp']              = date('c');
		$request['RequestedShipment']['ServiceType']                = 'SMART_POST'; // valid values STANDARD_OVERNIGHT, PRIORITY_OVERNIGHT, FEDEX_GROUND, ...
		$request['RequestedShipment']['SmartPostDetail']['Indicia'] = MODULE_SHIPPING_FEDEX_SMARTPOST_INDICIA;
		if (MODULE_SHIPPING_FEDEX_SMARTPOST_ENDORSEMENT != 'NONE') {
			$request['RequestedShipment']['SmartPostDetail']['AncillaryEndorsement'] = MODULE_SHIPPING_FEDEX_SMARTPOST_ENDORSEMENT;
		}
		if (MODULE_SHIPPING_FEDEX_SMARTPOST_SPECIALSERVICES == 'true') {
			$request['RequestedShipment']['SmartPostDetail']['SpecialServices'] = MODULE_SHIPPING_FEDEX_SMARTPOST_SPECIALSERVICES;
		}
		$request['RequestedShipment']['SmartPostDetail']['HubId'] = (int) MODULE_SHIPPING_FEDEX_SMARTPOST_HUBID;
		$request['RequestedShipment']['PackagingType']            = 'YOUR_PACKAGING'; // valid values FEDEX_BOX, FEDEX_PAK, FEDEX_TUBE, YOUR_PACKAGING, ...
		
		$request['WebAuthenticationDetail']                     = array(
			'UserCredential' => array(
				'Key' => $this->fedex_key,
				'Password' => $this->fedex_pwd
			)
		);
		$request['ClientDetail']                                = array(
			'AccountNumber' => $this->fedex_act_num,
			'MeterNumber' => $this->fedex_meter_num
		);
		//print_r($request['WebAuthenticationDetail']);
		//print_r($request['ClientDetail']);
		//exit;									  
		$request['RequestedShipment']['Shipper']                = array(
			'Address' => array(
				'StreetLines' => array(
					MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_1,
					MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_2
				), // Origin details
				'City' => MODULE_SHIPPING_FEDEX_SMARTPOST_CITY,
				'StateOrProvinceCode' => MODULE_SHIPPING_FEDEX_SMARTPOST_STATE,
				'PostalCode' => MODULE_SHIPPING_FEDEX_SMARTPOST_POSTAL,
				'CountryCode' => $this->country
			)
		);
		$request['RequestedShipment']['Recipient']              = array(
			'Address' => array(
				'StreetLines' => array(
					$street_address,
					$street_address2
				), // customer street address
				'City' => $city, //customer city
				'StateOrProvinceCode' => $state, //customer state
				'PostalCode' => $postcode, //customer postcode
				'CountryCode' => $country_id
			)
		); //customer county code
		//print_r($request['RequestedShipment']['Recipient'])	;
		//exit;									   
		$request['RequestedShipment']['ShippingChargesPayment'] = array(
			'PaymentType' => 'SENDER',
			'Payor' => array(
				'AccountNumber' => $this->fedex_act_num, // Replace 'XXX' with payor's account number
				'CountryCode' => $this->country
			)
		);
		$request['RequestedShipment']['RateRequestTypes']       = 'LIST';
		$request['RequestedShipment']['PackageCount']           = $shipping_num_boxes;
		$request['RequestedShipment']['PackageDetail']          = 'INDIVIDUAL_PACKAGES';
		$fedex_shipping_weight                                  = $shipping_weight;
		
        // shipping boxes manager
        if (MODULE_SHIPPING_BOXES_MANAGER_STATUS == 'true' && is_array($packed_boxes) && sizeof($packed_boxes) > 0) {		 
          $this->fedex_shipping_num_boxes = sizeof($packed_boxes);
          $this->fedex_shipping_weight = round(($this->fedex_shipping_weight / $shipping_num_boxes), 2); // use our number of packages rather than Zen Cart's calculation, package weight will still have to be an average since we don't know which products are in the box.
    
          //$shipping_weight = round(($this->total_weight / $shipping_num_boxes), 2); // use our number of packages rather than Zen Cart's calculation, package weight will still have to be an average since we don't know which products are in the box.
          $boxed_value = sprintf("%01.2f", $this->insurance / $this->fedex_shipping_num_boxes);
          $packages = array();
          foreach ($packed_boxes as $packed_box) {
            $packed_box['weight'] = $packed_box['weight'] - ($free_weight / count($packed_boxes));
            if ($packed_box['weight'] <= 0) $packed_box['weight'] = 0.1;
            
            $package = array(
              'Weight' => array(
                'Value' => $packed_box['weight'], // this is an averaged value
                'Units' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT
              ),
              'GroupPackageCount' => 1
            );
            if (isset($packed_box['length']) && isset($packed_box['width']) && isset($packed_box['height'])) {
              $package['Dimensions'] = array(
                'Length' => ($packed_box['length'] >= 1 ? $packed_box['length'] : 1),
                'Width' => ($packed_box['width'] >= 1 ? $packed_box['width'] : 1),
                'Height' => ($packed_box['height'] >= 1 ? $packed_box['height'] : 1),
                'Units' => (MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT == 'LB' ? 'IN' : 'CM') 
              );
            }
            $packages[] = $package;
          }
    
          $request['RequestedShipment']['RequestedPackageLineItems'] = $packages;
        } else {
    		if ($fedex_shipping_weight < 1)
    			$fedex_shipping_weight = '1.00'; // minimum shipping weight is 1.00lb
    		if ($fedex_shipping_weight > 70) return false; // maximum weight is 70lb, make sure to set a maximum package weight of less than this maximum 
    		
    		for ($i = 1; $i <= $shipping_num_boxes; $i++) {
    			$request['RequestedShipment']['RequestedPackageLineItems'][] = array(
    				'Weight' => array(
    					'Value' => $fedex_shipping_weight,
    					'Units' => MODULE_SHIPPING_FEDEX_SMARTPOST_WEIGHT
    				),
    				'GroupPackageCount' => $i,
    				'Dimensions' => array(
                        'Length' => ($packed_boxes[0]['length'] >= 1 ? $packed_boxes[0]['length'] : 1),
                        'Width' => ($packed_boxes[0]['width'] >= 1 ? $packed_boxes[0]['width'] : 6),
                        'Height' => ($packed_boxes[0]['height'] >= 1 ? $packed_boxes[0]['height'] : 4),
                        'Units' => (MODULE_SHIPPING_FEDEX_SMARTPOST_WEIGHT == 'LB' ? 'IN' : 'CM') 
                      )
    			);
    		}   
        }
/*
    echo '<!-- ';
    echo '<pre>';
    print_r($request);                                                                                                                                                                                                                   
    echo '</pre>';
    echo ' -->';
*/
		try {
			
		$response = $client->getRates($request);

		if ($response->HighestSeverity != 'FAILURE' && $response->HighestSeverity != 'ERROR') {
			//echo '<pre>';
			//print_r($response);
			//echo '</pre>';
			//$cost = $response->RateReplyDetails->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Amount;

		      if(MODULE_SHIPPING_FEDEX_SMARTPOST_DEBUG == 'true' ){
		        $log_time_stamp = microtime();
		      //  error_log('['. strftime("%Y-%m-%d %H:%M:%S") .'] '. var_export($request, true), 3, DIR_FS_LOGS . '/fedexsmartpost-requests-' . $log_time_stamp . '.log');
		      //  error_log('['. strftime("%Y-%m-%d %H:%M:%S") .'] '. var_export($response, true), 3, DIR_FS_LOGS . '/fedexsmartpost-responses-' . $log_time_stamp . '.log');
			error_log('['. date('Ymd-His') .'] '. var_export($request, true), 3, DIR_FS_LOGS . '/fedexsmartpost-requests-' . $log_time_stamp . '.log');
		       	error_log('['. date('Ymd-His') .'] '. var_export($response, true), 3, DIR_FS_LOGS . '/fedexsmartpost-responses-' . $log_time_stamp . '.log');
		      }

			$showAccountRates = true;
			if (MODULE_SHIPPING_FEDEX_SMARTPOST_RATES == 'LIST') {
				foreach ($response->RateReplyDetails->RatedShipmentDetails as $RatedShipmentDetails) {
					//print_r($RatedShipmentDetails);
					if ($RatedShipmentDetails->ShipmentRateDetail->RateType == 'PAYOR_LIST_PACKAGE') {
						$cost = $RatedShipmentDetails->ShipmentRateDetail->TotalNetCharge->Amount;
						$cost = (float) round(preg_replace('/[^0-9.]/', '', $cost), 2);
						if ($cost > 0)
							$showAccountRates = false;
					}
				}
			}
			if ($showAccountRates) {
				$cost = $response->RateReplyDetails->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Amount;

				$cost = (float) round(preg_replace('/[^0-9.]/', '', $cost), 2);
			}

			$this->quotes = array(
				'id' => $this->code,
				'module' => MODULE_SHIPPING_FEDEX_SMARTPOST_TEXT_TITLE . $show_box_weight,
				'methods' => array(
					array(
						'id' => $this->code,
					//	'title' => MODULE_SHIPPING_FEDEX_SMARTPOST_TEXT_TITLE,
						'title' => MODULE_SHIPPING_FEDEX_SMARTPOST_TEXT_METHOD,
						'cost' => $cost
					)
				)
			);
			if ($this->tax_class > 0) {
				$this->quotes['tax'] = zen_get_tax_rate($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']);
			}
		} else {
			$message = 'Error in processing transaction.<br /><br />';
			foreach ($response->Notifications as $notification) {
				if (is_array($response->Notifications)) {
					$message .= $notification->Severity;
					$message .= ': ';
					$message .= $notification->Message . '<br />';
				} else {
					$message .= $notification . '<br />';
				}
			}
			$this->quotes = array(
				'module' => $this->title,
				'error' => $message
			);
		}
		if (zen_not_null($this->icon))
			$this->quotes['icon'] = zen_image($this->icon, $this->title, '', '', 'style="vertical-align:middle;"');

		} catch (Exception $e) {
      			$this->quotes = array('module' => $this->title,
                            'error'  => 'Sorry, the FedEx.com server is currently not responding, please try again later.');
    		}
		
		return $this->quotes;
	}
	
	function _setInsuranceValue($order_amount) {
		//if ($order_amount > MODULE_SHIPPING_FEDEX_SMARTPOST_INSURE) {
		if (defined('MODULE_SHIPPING_FEDEX_SMARTPOST_INSURE') && ($order_amount > MODULE_SHIPPING_FEDEX_SMARTPOST_INSURE)) {
			$this->insurance = sprintf("%01.2f", $order_amount);
		} else {
			$this->insurance = 0;
		}
	}
	
	function _setDropOff() {
		switch (MODULE_SHIPPING_FEDEX_SMARTPOST_DROPOFF) {
			case '1':
				return 'REGULAR_PICKUP';
				break;
			case '2':
				return 'REQUEST_COURIER';
				break;
			case '3':
				return 'DROP_BOX';
				break;
			case '4':
				return 'BUSINESS_SERVICE_CENTER';
				break;
			case '5':
				return 'STATION';
				break;
		}
	}
	
	function check() {
		global $db;
		if (!isset($this->_check)) {
			$check_query  = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS'");
			$this->_check = $check_query->RecordCount();
			if ($this->_check && defined('MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS')) {
				$this->version = MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS;
				while ($this->version != '1.3.1') {
					switch ($this->version) {
						case '1.2.0':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.1', '', '6', '0', now())");
							$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('FedEx Rates','MODULE_SHIPPING_FEDEX_SMARTPOST_RATES','LIST','FedEx Rates (LIST = FedEx default rates, ACCOUNT = Your discounted rates)','6','0','zen_cfg_select_option(array(\'LIST\',\'ACCOUNT\'),',now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.0', 'success');
							$this->version = '1.2.0';
							break;
						case '1.2.1':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.2', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.1', 'success');
							$this->version = '1.2.2';
							break;
						case '1.2.2':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.3', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.3', 'success');
							$this->version = '1.2.3';
							break;
						case '1.2.3':
							// perform upgrade
							$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Tax Class', 'MODULE_SHIPPING_FEDEX_SMARTPOST_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', '6', '25', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.4', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.4', 'success');
							$this->version = '1.2.4';
							break;
						case '1.2.4':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.5', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.5', 'success');
							$this->version = '1.2.5';
							break;
						case '1.2.5':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.6', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.6', 'success');
							$this->version = '1.2.6';
							break;
						case '1.2.6':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.7', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.7', 'success');
							$this->version = '1.2.7';
							break;	
						case '1.2.7':
							// perform upgrade
							$db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.8', '', '6', '0', now())");
							$messageStack->add('Updated FedEx SmartPost to v1.2.8', 'success');
							$this->version = '1.2.8';
							break;
                        case '1.2.8':
                            // perform upgrade
                            $db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.9', '', '6', '0', now())");
                            $messageStack->add('Updated FedEx SmartPost to v1.2.9', 'success');
                            $this->version = '1.2.9';
                            break;
                        case '1.2.9':
                            // perform upgrade
                            $db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.3.0', '', '6', '0', now())");
                            $messageStack->add('Updated FedEx SmartPost to v1.3.0', 'success');
                            $this->version = '1.3.0';
                            break;
                        case '1.3.0':
                            // perform upgrade
                            $db->Execute("replace into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.3.1', '', '6', '0', now())");
           		 			if (!defined('MODULE_SHIPPING_FEDEX_SMARTPOST_DEBUG'))
              					$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug', 'MODULE_SHIPPING_FEDEX_SMARTPOST_DEBUG', 'false', 'Turn On Debugging?', '6', '99', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
                            $messageStack->add('Updated FedEx SmartPost to v1.3.1', 'success');
                            $this->version = '1.3.1';
                            break;
						default:
							$this->version = '1.3.1';
							// break all the loops
							break 2;
					}
				}
			}
		}
		return $this->_check;
	}
	
	function install() {
		global $db;
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable FedEx SmartPost','MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS','true','Do you want to offer FedEx shipping?','6','0','zen_cfg_select_option(array(\'true\',\'false\'),',now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION', '1.2.9', '', '6', '0', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Key', 'MODULE_SHIPPING_FEDEX_SMARTPOST_KEY', 'CC9uiY62KI10mPGn', 'Enter FedEx Web Services Key', '6', '1', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Password', 'MODULE_SHIPPING_FEDEX_SMARTPOST_PWD', 'xJK8dnC5PCJzrWETEBtIovDLC', 'Enter FedEx Web Services Password', '6', '2', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Account Number', 'MODULE_SHIPPING_FEDEX_SMARTPOST_ACT_NUM', '510087402', 'Enter FedEx Account Number', '6', '3', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Meter Number', 'MODULE_SHIPPING_FEDEX_SMARTPOST_METER_NUM', '118503951', 'Enter FedEx Meter Number', '6', '4', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Weight Units', 'MODULE_SHIPPING_FEDEX_SMARTPOST_WEIGHT', 'LB', 'Weight Units:', '6', '10', 'zen_cfg_select_option(array(\'LB\', \'KG\'), ', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('First line of street address', 'MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_1', '', 'Enter the first line of your ship-from street address, required', '6', '20', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Second line of street address', 'MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_2', '', 'Enter the second line of your ship-from street address, leave blank if you do not need to specify a second line', '6', '21', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('City name', 'MODULE_SHIPPING_FEDEX_SMARTPOST_CITY', '', 'Enter the city name for the ship-from street address, required', '6', '22', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('State or Province name', 'MODULE_SHIPPING_FEDEX_SMARTPOST_STATE', '', 'Enter the 2 letter state or province name for the ship-from street address, required for Canada and US', '6', '23', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Postal code', 'MODULE_SHIPPING_FEDEX_SMARTPOST_POSTAL', '', 'Enter the postal code for the ship-from street address, required', '6', '24', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Phone number', 'MODULE_SHIPPING_FEDEX_SMARTPOST_PHONE', '', 'Enter a contact phone number for your company, required', '6', '25', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('HubId', 'MODULE_SHIPPING_FEDEX_SMARTPOST_HUBID', '5531', 'Enter the HubId for SmartPost (Use 5531 for testing)', '6', '30', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Indicia', 'MODULE_SHIPPING_FEDEX_SMARTPOST_INDICIA', 'PARCEL_SELECT', 'Specify the Indicia Type', '6', '30', 'zen_cfg_select_option(array(\'MEDIA_MAIL\',\'PARCEL_SELECT\',\'PRESORTED_BOUND_PRINTED_MATTER\',\'PRESORTED_STANDARD\'),', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ancillary Endorsement', 'MODULE_SHIPPING_FEDEX_SMARTPOST_ENDORSEMENT', 'NONE', 'Specify an endorsement type', '6', '30', 'zen_cfg_select_option(array(\'NONE\',\'ADDRESS_CORRECTION\',\'CARRIER_LEAVE_IF_NO_RESPONSE\',\'CHANGE_SERVICE\',\'FORWARDING_SERVICE\',\'RETURN_SERVICE\'),', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Special Services', 'MODULE_SHIPPING_FEDEX_SMARTPOST_SPECIALSERVICES', 'false', 'Add delivery confirmation?', '6', '30', 'zen_cfg_select_option(array(\'true\',\'false\'),', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Drop off type', 'MODULE_SHIPPING_FEDEX_SMARTPOST_DROPOFF', '1', 'Dropoff type (1 = Regular pickup, 2 = request courier, 3 = drop box, 4 = drop at BSC, 5 = drop at station)?', '6', '30', 'zen_cfg_select_option(array(\'1\',\'2\',\'3\',\'4\',\'5\'),', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Currency Code', 'MODULE_SHIPPING_FEDEX_SMARTPOST_CURRENCY', 'USD', 'Enter 3 digit currency code (default = USD)', '6', '30', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('FedEx Rates','MODULE_SHIPPING_FEDEX_SMARTPOST_RATES','LIST','FedEx Rates (LIST = FedEx default rates, ACCOUNT = Your discounted rates)','6','0','zen_cfg_select_option(array(\'LIST\',\'ACCOUNT\'),',now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Shipping Zone', 'MODULE_SHIPPING_FEDEX_SMARTPOST_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', '6', '98', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Tax Class', 'MODULE_SHIPPING_FEDEX_SMARTPOST_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', '6', '25', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort Order', 'MODULE_SHIPPING_FEDEX_SMARTPOST_SORT_ORDER', '0', 'Sort order of display.', '6', '99', now())");
		$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug', 'MODULE_SHIPPING_FEDEX_SMARTPOST_DEBUG', 'false', 'Turn On Debugging?', '6', '99', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
	}
	
	function remove() {
		global $db;
		$db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key in ('" . implode("','", $this->keys()) . "')");
	}
	
	function keys() {
		return array(
			'MODULE_SHIPPING_FEDEX_SMARTPOST_STATUS',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_VERSION',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_KEY',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_PWD',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_ACT_NUM',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_METER_NUM',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_WEIGHT',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_1',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_ADDRESS_2',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_CITY',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_STATE',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_POSTAL',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_PHONE',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_DROPOFF',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_CURRENCY',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_HUBID',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_INDICIA',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_SPECIALSERVICES',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_ENDORSEMENT',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_RATES',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_TAX_CLASS',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_SORT_ORDER',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_ZONE',
			'MODULE_SHIPPING_FEDEX_SMARTPOST_DEBUG'
		);
	}
}
?>
