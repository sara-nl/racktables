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
		$snmphash[$ifIndex][$oid]  =  $oidvalue;
	}
	//Remove unwanted interfaces that do not need to be stored in racktables
	$regstring = "(demux|cbp|fxp|lsi|pip|pp|dsc|tap|gre|ipip|pime|pimd|mtun|16384|16385|32767|32769|ud-|ip-|gr-|pd-|pe-|vt-|mt-|lt-|lc-)";
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
	

	foreach ($snmphash as $key => $value) {
		
		// update ports
		
		$ifDescr = $value['ifDescr'];
		$portexists=FALSE;
		foreach ($ports as $port) {
			if ($ifDescr == $port['name']) {
				
				$portexists=TRUE; 
			}
		}
		if ($portexists == FALSE) {
			print $router['name']. ": ".$ifDescr. " added to database\n";
			usePreparedInsertBlade ( 'Port', array ('object_id' => $router['id'],'name' => $ifDescr, 'label' => $value['ifAlias'], 'iif_id' => 1, 'type' => 24, 'l2address' => $ifPhysAddress));
			syslog(LOG_INFO, $router['name']. ": ".$ifDescr. " added to database");
		
		}
	
		// ipdate IP allocations	
		
		if (strlen($value['ipAdEntAddr']) > 0) {
			$allocexists=FALSE;
			foreach ($ip_allocs as $ip_alloc) {
			
				$ip_bin = ip4_int2bin($ip_alloc['ip']);
				$ip = ip4_format ($ip_bin);
				
				
				if ($value['ipAdEntAddr'] == $ip) {
					$allocexists=TRUE; 
				}
			}
			if ($allocexists == FALSE) {
				
				
				$int_ip = ip4_bin2int(ip4_parse($value['ipAdEntAddr']));
				usePreparedInsertBlade ( 'IPv4Allocation', array ('object_id' => $router['id'],'ip' => $int_ip, 'name' => $value['ifDescr'], 'type' => 'router')); 
				syslog(LOG_INFO, $router['name']. ": ".$value['ipAdEntAddr']. " added to database");
				
			}
		}	
	}
}

// Close syslog
syslog(LOG_INFO, "--- racktables_Port_update.php finished ---");
closelog();

?>
