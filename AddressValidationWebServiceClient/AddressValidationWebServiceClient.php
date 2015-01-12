<?php 
	/**************************************************************
	Developer: Rutherford Le
	Project: Magento eCommerce
	Core language: PHP, JavaScript, HTML, CSS
	System developed: Windows 7
	Purpose: This application utilizes Magento API to pass order 
	information Magento to Oracle
	Comments:
	**************************************************************/

				
	//CONNECTION

	for($y=0;$y<=100000;$y++){
	error_reporting(0);
	include '/includes/connect.php';


	require_once('../library/fedex-common.php');

	//The WSDL is not included with the sample code.
	//Please include and reference in $path_to_wsdl variable.
	$path_to_wsdl = "../wsdl/AddressValidationService_v3.wsdl"; 

	ini_set("soap.wsdl_cache_enabled", "0");

	$client = new SoapClient($path_to_wsdl, array('trace' => 1)); // Refer to http://us3.php.net/manual/en/ref.soap.php for more information

	$request['WebAuthenticationDetail'] = array(
		'UserCredential' => array(
			'Key' => getProperty('key'), 
			'Password' => getProperty('password')
		)
	);
	$request['ClientDetail'] = array(
		'AccountNumber' => getProperty('shipaccount'), 
		'MeterNumber' => getProperty('meter')
	);
	
	$request['TransactionDetail'] = array('CustomerTransactionId' => ' *** Address Validation Request using PHP ***');
	$request['Version'] = array(
		'ServiceId' => 'aval', 
		'Major' => '3', 
		'Intermediate' => '0', 
		'Minor' => '0'
	);
	
	$request['InEffectAsOfTimestamp'] = date('c');
	$inc = 101;
	$error = 0;
	addressvalidation($inc, 0, 0);
		//sleep(20); // wait 20 seconds
	
	}
	function addressValidation($inc, $error, $addressID1){
		global $connect;
		global $client;
		global $request;
		$array = array();
		$addressID1 = array();
		$x = 0;
		
		$resultOracle = oci_parse($connect, "SELECT FCPA_ADDRESS_ID,ORIG_ADDRESS1,ORIG_ADDRESS2,ORIG_CITY,ORIG_STATE,ORIG_POSTAL_CODE
											FROM arf_customer_address_master
											WHERE SOURCE <> 'ORACLE' AND PROCESS_STATUS = 'WAITING_TO_CLEAN' AND ORIG_ADDRESS1 IS NOT NULL
											AND ROWNUM < :inc"); //new values or flag set to modified

		oci_bind_by_name($resultOracle, ":inc", $inc);
		$r = oci_execute($resultOracle);
		if (!$r) {
			$e = oci_error($resultOracle);
			throw new Exception($e['message']);
		}
		
		$address = array();
		while (($row1 = oci_fetch_array($resultOracle, OCI_BOTH)) != false) {
			unset($address);
			$address[0] = trim(htmlspecialchars($row1['ORIG_ADDRESS1']));
			$address[1] = @trim(htmlspecialchars($row1['ORIG_ADDRESS2']));

			$array[$x]['ClientReferenceId'] = trim($row1['FCPA_ADDRESS_ID']);
			$addressID1[$row1['FCPA_ADDRESS_ID']] = trim(htmlspecialchars($row1['FCPA_ADDRESS_ID']));
			$array[$x]['Address']['StreetLines'] = $address;
			$array[$x]['Address']['City'] =  @trim(htmlspecialchars($row1['ORIG_CITY']));
			$array[$x]['Address']['StateOrProvinceCode'] =  @trim(htmlspecialchars($row1['ORIG_STATE']));
			$array[$x]['Address']['PostalCode'] =  @trim(htmlspecialchars($row1['ORIG_POSTAL_CODE']));
			//$array[$x]['Address']['CountryCode'] =  @$row1['COUNTRY'];
			$array[$x]['Address']['Residential'] = 1;
			$x++;

		}
		
		if($inc < 2){
			errorValidate($addressID1);
			$inc = 101;
			return;
		}
		$request['AddressesToValidate'] = $array;

		try {
			if(setEndpoint('changeEndpoint')){
				$newLocation = $client->__setLocation(setEndpoint('endpoint'));
			}

			$response = $client ->addressValidation($request);

			if ($response -> HighestSeverity != 'FAILURE' && $response -> HighestSeverity != 'ERROR'){
				foreach($response -> AddressResults as $addressResult){
					unset($verAddress, $verAddress2);
					//echo 'Client Reference Id: ' . $addressResult->ClientReferenceId . Newline;
					//echo 'State: ' . $addressResult->State . Newline;
					//echo 'Classification: ' . $addressResult->Classification . Newline;
					//echo 'Proposed Address:' . Newline;
					$verAddress = $addressResult->EffectiveAddress->StreetLines;

					if(is_array($verAddress)){
						$verAddress1 = $verAddress[0];
						$verAddress2 = $verAddress[1];
					}
					else
						$verAddress1 =  $verAddress;
					
					$verCity = $addressResult->EffectiveAddress->City;
					$verState = $addressResult->EffectiveAddress->StateOrProvinceCode;
					$verZip = $addressResult->EffectiveAddress->PostalCode;
					$addType = $addressResult->Classification;
					$addressID = $addressResult->ClientReferenceId;
					$addressValidated = 'ADDR_VALIDATED';

					$sql="UPDATE arf_customer_address_master 
						SET ADDRESS1 = :verAddress1,  ADDRESS2 = :verAddress2, CITY = :verCity, 
						STATE = :verState, POSTAL_CODE = :verZip, ADDRESS_TYPE = :addType, PROCESS_STATUS = :addressValidated
						WHERE FCPA_ADDRESS_ID = :addressID";
						
					$stid = oci_parse($connect, $sql);
				
					oci_bind_by_name($stid, ":verAddress1", $verAddress1);
					oci_bind_by_name($stid, ":verAddress2", $verAddress2);
					oci_bind_by_name($stid, ":verCity", $verCity);
					oci_bind_by_name($stid, ":verState", $verState);
					oci_bind_by_name($stid, ":verZip", $verZip);
					oci_bind_by_name($stid, ":addressID", $addressID);
					oci_bind_by_name($stid, ":addType", $addType);
					oci_bind_by_name($stid, ":addressValidated", $addressValidated);
					
					$r = oci_execute($stid);
					if (!$r) {
						$e = oci_error($stid);
						trigger_error(htmlentities($e['message']), E_USER_ERROR);
					}
					

				/*
				if(array_key_exists("Attributes", $addressResult)){
					echo Newline . 'Address Attributes' . Newline;
					foreach($addressResult->Attributes as $attribute){
						echo '&nbsp;&nbsp;' . $attribute -> Name . ': ' . $attribute -> Value . Newline; 
					}
				}
				*/
				//echo Newline;
				}

				printSuccess($client, $response);
			if($error == 1) //error is always 0 unless invalid
				addressvalidation($inc, $error, $addressID1);
			}else if($inc >= 3){
				printError($client, $response);
				$error = 1;
					
				addressvalidation($inc/2, $error, $addressID1); //If passed, error will be marked 1 and halved until inc less than 3
			} 

			writeToLog($client);    // Write to log file   
		} catch (SoapFault $exception) {
			printFault($exception, $client);
		}
		return;
	}
	function errorValidate($addressID1){ // inc less than 3, mark invalid address ERROR
		global $connect;
		global $client;
		global $request;
		$addressValidated = 'ERROR';
				
		foreach($addressID1 as $key => $value){

			$sql="UPDATE arf_customer_address_master 
					SET PROCESS_STATUS = :addressValidated
					WHERE FCPA_ADDRESS_ID = :value";
							
			$stid = oci_parse($connect, $sql);
					
			//oci_bind_by_name($stid, ":addressID", $addressID);
			oci_bind_by_name($stid, ":addressValidated", $addressValidated);
			oci_bind_by_name($stid, ":value", $value);
			
			$r = oci_execute($stid);
			if (!$r) {
				$e = oci_error($stid);
				trigger_error(htmlentities($e['message']), E_USER_ERROR);
			}
		}
		return;
	}

	oci_close($connect);

?>