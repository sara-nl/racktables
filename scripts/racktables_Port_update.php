<?php

// Call neccesary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include '/var/www/racktables/inc/init.php';

// Open syslog
openlog('Port_check', LOG_PID | LOG_ODELAY,LOG_LOCAL7);
syslog(LOG_INFO, "--- racktables_Port_update.php started ---");

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
	$regstring = "(pfe|pfh|demux|cbp|fxp|lsi|pip|pp|dsc|tap|gre|ipip|pime|pimd|mtun|16384|16385|32767|32769|ud-|ip-|gr-|pd-|pe-|vt-|mt-|lt-|lc-)";
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
##
#
# Main Procedure
#
##

// Make a list of all router objects
$result = usePreparedSelectBlade ("select Object.id, Object.name ,AttributeValue.string_value from Object 
												LEFT JOIN AttributeValue ON AttributeValue.object_id = Object.id 
												LEFT JOIN Dictionary ON Object.objtype_id = Dictionary.dict_key 
												LEFT JOIN Attribute ON AttributeValue.attr_id=Attribute.id
												where Dictionary.dict_value=\"Router\"  and Attribute.name=\"SNMP Community\";");
while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
	$routers[]=$row;
}

foreach ($routers as $router) {
	$iphash = getSNMP_IP_Info($router['name'],$router['string_value']);
	$snmphash = getSNMP_IF_Info($router['name'],$router['string_value']);
	foreach ($iphash as $key => $value) {
		if (array_key_exists($key,$snmphash)) {
		   $snmphash[$key]['ipAdEntAddr'] = $value['ipAdEntAddr'];
		}
	}
	
	// read the complete list of ports from the database
	$result = usePreparedSelectBlade ('select Object.*, Port.* from Object LEFT JOIN Port ON Port.object_id = Object.id where Object.objtype_id=7 and Object.id='.$router['id'].';');
	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
		$ports[]=$row;
	}
	$result = usePreparedSelectBlade ('select * from IPv4Allocation where object_id='.$router['id'].';');
	while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
		$ip_allocs[]=$row;
	}
	
	$snmpallocindex=1;				// recursive_array_search function doesnt work when index=0 ... :-(
	
	
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
			usePreparedInsertBlade ( 'Port', array ('object_id' => $router['id'],'name' => $ifDescr, 'label' => $value['ifAlias'], 'iif_id' => 1, 'type' => 24, 'l2address' => $value['ifPhysAddress']));
			syslog(LOG_INFO, $router['name']. ": ".$ifDescr. " added to database");
		
		}
	
		// build IP allocations	hash
		
		if (strlen($value['ipAdEntAddr']) > 0) {			// select only smnphashes with an ip address
			$snmp_allocs[$snmpallocindex] = array('object_id' => $router['id'] , 'ip' => $value['ipAdEntAddr'], 'name' => $ifDescr);
			$snmpallocindex ++;
		}
	}
	foreach ($ports as $port) {
		if (recursive_array_search($port['name'], $snmphash) == FALSE) {
			usePreparedDeleteBlade ( 'Port', array ('object_id' => $router['id'], 'name' => $port['name'], 'type' => 24)); 
			syslog(LOG_INFO, $router['name']. ": ".$port['name']. " ".$port['label']. " removed from database");
		}
	}

	
	// Check and update IP allocations in Racktables versus input from SNMP
	foreach ($snmp_allocs as $snmp_alloc) {
		
		$alloc_status = "UNKNOWN";
		foreach ($ip_allocs as $ip_alloc) {
			
			$ip_bin = ip4_int2bin($ip_alloc['ip']);
				$ip = ip4_format ($ip_bin);

			if ($snmp_alloc['ip'] == $ip) {
				$alloc_status = "FOUND";
				if ($snmp_alloc['name'] != $ip_alloc['name']) {
					usePreparedUpdateBlade ( 'IPv4Allocation', array ('name' => $snmp_alloc['name']), array ('ip' => $ip_alloc['ip'], 'name' => $ip_alloc['name']) );
					syslog(LOG_INFO, $router['name']. ": ".$snmp_alloc['ip']." FROM: " . $ip_alloc['name'] . " TO: " . $snmp_alloc['name'] ." changed in database ");
				}
			}
		}
		if ($alloc_status == "UNKNOWN") {
			$int_ip = ip4_bin2int(ip4_parse($snmp_alloc['ip']));
			usePreparedInsertBlade ( 'IPv4Allocation', array ('object_id' => $router['id'],'ip' => $int_ip, 'name' => $snmp_alloc['name'], 'type' => 'router')); 
			syslog(LOG_INFO, $router['name']. ": ".$snmp_alloc['ip']. " added to database");
		}
	}

	
	// Loop again to locate unused allocations in the database, and remove them
	foreach ($ip_allocs as $ip_alloc) {
		
		$ip_bin = ip4_int2bin($ip_alloc['ip']);
		$ip = ip4_format ($ip_bin);
		
		if (recursive_array_search($ip, $snmp_allocs) == FALSE) {
			usePreparedDeleteBlade ( 'IPv4Allocation', array ('object_id' => $router['id'],'ip' => $ip_alloc['ip'], 'name' => $ip_alloc['name'], 'type' => 'router')); 
			syslog(LOG_INFO, $router['name']. ": ".$ip. " ".$ip_alloc['name']. " removed from database");
		}
	}
	unset($iphash);
        unset($snmphash);
        unset($ports);
	unset($ip_allocs);
	unset($snmp_allocs);

}

// Close syslog
syslog(LOG_INFO, "--- racktables_Port_update.php finished ---");
closelog();

?>
