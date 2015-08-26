<?php

// Call neccesary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include '/var/www/racktables/inc/init.php';


// Open syslog
openlog('Switch_check', LOG_PID | LOG_ODELAY,LOG_LOCAL7);
syslog(LOG_INFO, "--- racktables_Switch_update.php started ---");

function getSNMP_ifDescr($host, $SNMP_community, $ifIndex) {
	snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
	$ifDescr = snmpget($host, $SNMP_community, "ifDescr." . $ifIndex);
	return $ifDescr;
}

function getSNMP_IF_Info ($host, $SNMP_community) {
	// get SNMP info
	snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);
	$b = snmprealwalk($host, $SNMP_community, "ifEntry");
	$c = snmprealwalk($host, $SNMP_community, "ifAlias");
	
	$a = array_merge($b,$c);
	
	foreach ($a as $key => $value) {
		$oidarray = explode('.', substr($key, strpos($key,':')+2));
		$ifIndex = $oidarray[1];
		$oid = $oidarray[0];
	   $oidvalue = substr($value, strpos($value,':')+2);
		$snmphash[$ifIndex][$oid] = trim($oidvalue);
	}
	//Remove unwanted interfaces that do not need to be stored in racktables
	$regstring = "(pfe|pfh|demux|cbp|fxp|lsi|pip|pp|dsc|tap|gre|ipip|pime|pimd|mtun|16384|16385|32767|32769|ud-|ip-|gr-|pd-|pe-|vt-|mt-|lt-|lc-|ae|bme|lo|vlan|vme|\.|StackSub-|StackPort|Null|Port-channel|Vlan)";
	foreach ($snmphash as $key => $value) {
		if (preg_match($regstring,$value['ifDescr'])) { 
			unset($snmphash[$key]);
		} 
	}
	// parse MAC address to be able to import them into racktables
	foreach ($snmphash as $key => $value) {
		$ifPhysAddressArray = explode(":", $value['ifPhysAddress']);
		$ifPhysAddress="";
		foreach ($ifPhysAddressArray as $byte) {
				if (strlen($byte) == 1) $byte = "0".$byte;
				$ifPhysAddress=$ifPhysAddress.$byte;
		}
		$snmphash[$key]['ifPhysAddress'] = $ifPhysAddress;
	}
	return $snmphash;
}

function getSNMP_IP_Info ($host, $SNMP_community) {
    // get SNMP info
    snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);
    $a = snmprealwalk($host, $SNMP_community, "ipAdEntIfIndex");

    foreach ($a as $key => $value) {
        $oidarray = explode('.', substr($value, strpos($value,':')+2));
        $ifIndex = $oidarray[0];
        $oidvalue = substr($key, strpos($key,'.')+1);
        $oidvaluearray = explode('.',$oidvalue);
        if (sizeof($oidvaluearray) > 4) {
            unset($oidvaluearray[0]);
            $oidvalue = implode(".",$oidvaluearray);
    }

	$snmphash[$ifIndex] = array( 'ipAdEntAddr' => $oidvalue);

    //Remove unwanted ip addresses that do not need to be stored in racktables
	$regstring = "(128\.0\.0|127\.0\.0)";
	foreach ($snmphash as $key => $value) {
		if (preg_match($regstring,$value['ipAdEntAddr'])) { 
			unset($snmphash[$key]);
		} 
	}
	}
	return $snmphash;
}


function recursive_array_search($needle,$haystack) {
    foreach($haystack as $key=>$value) {
        $current_key=$key;
        if($needle===$value OR (is_array($value) && recursive_array_search($needle,$value))) {
        	
            return $current_key;
        }
    }
    return false;
}

function getSwitchInfo() {
	// Make a list of all switch objects having valid hostname and snmp_community
	// Make a list of all switches / routers

	// second run to fetch FQDN
	$result = usePreparedSelectBlade ("SELECT Object.id, Object.name ,AttributeValue.string_value as SNMP_community from Object 
										LEFT JOIN AttributeValue ON AttributeValue.object_id = Object.id 
										LEFT JOIN Dictionary ON Object.objtype_id = Dictionary.dict_key 
										WHERE (Dictionary.dict_value=\"Router\" OR Dictionary.dict_value=\"Network Switch\") AND AttributeValue.attr_id=(select id from Attribute where name=\"SNMP Community\");");

	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
	    $switches[$row['id']]['SNMP_community']=$row['SNMP_community'];
	    $switches[$row['id']]['id']=$row['id'];
	    $switches[$row['id']]['name']=$row['name'];
	}
	$result = usePreparedSelectBlade ("SELECT Object.id, Object.name ,AttributeValue.string_value as FQDN from Object 
										LEFT JOIN AttributeValue ON AttributeValue.object_id = Object.id 
										LEFT JOIN Dictionary ON Object.objtype_id = Dictionary.dict_key 
										WHERE (Dictionary.dict_value=\"Router\" OR Dictionary.dict_value=\"Network Switch\") AND AttributeValue.attr_id=(select id from Attribute where name=\"FQDN\");");

	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
	    $switches[$row['id']]['FQDN']=$row['FQDN'];
	}

	foreach ($switches as $key => $value) {
		//if ($value['FQDN'] != "grid-r1.rtr.sara.nl") unset($switches[$key]);
		if ( (!array_key_exists('FQDN', $value)) OR (!array_key_exists('SNMP_community', $value))) unset($switches[$key]);

	}
	return $switches;
}

##
#
# Main Procedure
#
##

$switches = getSwitchInfo();

foreach ($switches as $switch) {
	print $switch['FQDN']." ".$switch['SNMP_community'];
	$snmphash = getSNMP_IF_Info($switch['FQDN'],$switch['SNMP_community']);

	// read the complete list of ports from the database
	$result = usePreparedSelectBlade ('select Object.*, Port.* from Object LEFT JOIN Port ON Port.object_id = Object.id where Object.id='.$switch['id'].';');
	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
		$ports[]=$row;
	}

	foreach ($snmphash as $key => $value) {
	
		// update ports
		
		$ifDescr = $value['ifDescr'];
		$portexists=FALSE;
		foreach ($ports as $port) {
			if ($ifDescr == $port['name']) {
				$portexists=TRUE; 
				if ($value['ifAlias'] != $port['label']) {			// update if the label has changed
					usePreparedUpdateBlade ( 'Port', array ('label' => $value['ifAlias']), array ('name' => $port['name'],'object_id' => $router['id'], 'type' => 24) );
					syslog(LOG_INFO, $router['name']. ": ".$port['name']." FROM: " . $port['label'] . " TO: " . $value['ifAlias'] ." changed in database ");
				}
				if ( $value['ifPhysAddress'] != $port['l2address']) {			// update if the MAC address has changed
					usePreparedUpdateBlade ( 'Port', array ('l2address' => $value['ifPhysAddress']), array ('name' => $port['name'],'object_id' => $router['id'], 'type' => 24) );
					syslog(LOG_INFO, $router['name']. ": ".$port['name']." FROM: " . $port['l2address'] . " TO: " . $value['ifPhysAddress'] ." changed in database ");
				}
			}
		}
		if ($portexists == FALSE) {
			usePreparedInsertBlade ( 'Port', array ('object_id' => $switch['id'],'name' => $ifDescr, 'label' => $value['ifAlias'], 'iif_id' => 1, 'type' => 24, 'l2address' => $value['ifPhysAddress']));
			syslog(LOG_INFO, $switch['name']. ": ".$ifDescr. " added to database");
		
		}
	}
	foreach ($ports as $port) {
		if (recursive_array_search($port['name'], $snmphash) == FALSE) {
			usePreparedDeleteBlade ( 'Port', array ('object_id' => $switch['id'], 'name' => $port['name'], 'type' => 24)); 
			syslog(LOG_INFO, $router['name']. ": ".$port['name']. " ".$port['label']. " removed from database");
		}
	}
	unset($iphash);
    unset($snmphash);
    unset($ports);
}


foreach ($switches as $switch) {

	// Get IP allocation info from switches
	$result = usePreparedSelectBlade ('select * from IPv4Allocation where object_id='.$switch['id'].';');
	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
		$ip_allocs[]=$row;
	}
	var_dump($ip_allocs);

	print $switch['FQDN']." ".$switch['SNMP_community'];
	$snmp_ip_allocs = getSNMP_IP_Info($switch['FQDN'],$switch['SNMP_community']);


	foreach ($snmp_ip_allocs as $key => $value) {
		
		$ifDescr = getSNMP_ifDescr($switch['FQDN'],$switch['SNMP_community'],$key);
		$snmp_ip_allocs[$key]['ifDescr']= $ifDescr;
	}
	var_dump($snmp_ip_allocs);


	// Check and update IP allocations in Racktables versus input from SNMP
	foreach ($snmp_ip_allocs as $snmp_ip_alloc) {
		
		$alloc_status = "UNKNOWN";
		foreach ($ip_allocs as $ip_alloc) {
			
			$ip_bin = ip4_int2bin($ip_alloc['ip']);
			$ip = ip4_format ($ip_bin);
			$regstring = "(128\.0\.0|127\.0\.0)";
			


			if ($snmp_ip_alloc['ipAdEntAddr'] == $ip) {
				$alloc_status = "FOUND";
				if ($snmp_ip_alloc['ifDescr'] != $ip_alloc['name']) {
					usePreparedUpdateBlade ( 'IPv4Allocation', array ('name' => $snmp_ip_alloc['name']), array ('ip' => $ip_alloc['ip'], 'name' => $ip_alloc['name']) );
					syslog(LOG_INFO, $switch['name']. ": ".$snmp_ip_alloc['ipAdEntAddr']." FROM: " . $ip_alloc['name'] . " TO: " . $snmp_ip_alloc['ifDescr'] ." changed in database ");
				}
			}
		}
		if ($alloc_status == "UNKNOWN") {

			$int_ip = ip4_bin2int(ip4_parse($snmp_ip_alloc['ipAdEntAddr']));
			$type = "router";
			if (preg_match("(^10\.|^192\.168\.)",$snmp_ip_alloc['ipAdEntAddr'])) { 
				$type = "connected";
			};
			if (preg_match("(^lo|^Lo)",$snmp_ip_alloc['ifDescr'])) { 
				$type = "loopback";
			};
			usePreparedInsertBlade ( 'IPv4Allocation', array ('object_id' => $switch['id'],'ip' => $int_ip, 'name' => $snmp_ip_alloc['ifDescr'], 'type' => $type)); 
			syslog(LOG_INFO, $switch['name']. ": ".$snmp_ip_alloc['ipAdEntAddr']. " added to database");
		}
	}

	// Loop again to locate unused allocations in the database, and remove them
	foreach ($ip_allocs as $ip_alloc) {
		
		$ip_bin = ip4_int2bin($ip_alloc['ip']);
		$ip = ip4_format ($ip_bin);
		
		if (recursive_array_search($ip, $snmp_allocs) == FALSE) {
			usePreparedDeleteBlade ( 'IPv4Allocation', array ('object_id' => $switch['id'],'ip' => $ip_alloc['ip'], 'name' => $ip_alloc['name'])); // , 'type' => 'router'
			syslog(LOG_INFO, $switch['name']. ": ".$ip. " ".$ip_alloc['name']. " removed from database");
		}
	}
	
	unset($ip_allocs);
	unset($snmp_ip_allocs);
}


// Close syslog
syslog(LOG_INFO, "--- racktables_Port_update.php finished ---");
closelog();

?>
