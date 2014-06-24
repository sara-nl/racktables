##
#
# racktables_PTR_update.php
# 
# Script which supports Racktables to update PTR records in the IPv4 Space page. 
#
# (c) 2013 Erik Ruiter 
# erik.ruiter@surfsara.nl
#
# Add the following lines to etc/syslog-ng/syslog-ng.conf to store the racktables syslog entries:
#
# destination d_racktables { file("/var/log/racktables.log"); };
# filter f_racktables { facility(local7) and not filter(f_debug); };
# log { source(s_src); filter(f_racktables); destination(d_racktables); };
#
# This script can be added to crontab to perform a daily PTR check
#
##

<?php

// Call neccesary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include '/var/www/racktables/inc/init.php';

// Open syslog
openlog('PTR_check', LOG_PID | LOG_ODELAY,LOG_LOCAL7);
syslog(LOG_INFO, "--- racktables_PTR_update.php started ---");

//Select the complete list of IPv4 addresses and names from the database
$result = usePreparedSelectBlade ('select * from IPv4Address;');
while ($row = $result->fetch (PDO::FETCH_ASSOC))
        $addresses[]=$row;

//  Build a key / value hash
foreach($addresses as $address) {
	$addresshash[$address['ip']] = $address['name'];
}

// read the complete list of networks from the database
$result = usePreparedSelectBlade ('select * from IPv4Network;');
while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
	$data[]=$row;
}

foreach ($data as $net_id) {

	// retrieve detailed info for the network from Racktables
	$net = spotEntity ('ipv4net', $net_id['id']);
	$startip = ip4_bin2int($net['ip_bin']);
	$endip = $startip + pow(2,32-$net['mask']) -1;
	
	// Check all PTR records in the network from the first to the last address
	for ($i=$startip; $i <= $endip; $i++) {
 
		// do PTR lookup
		$ip_bin = ip4_int2bin($i);
      $straddr = ip4_format ($ip_bin);
		$ptrname = gethostbyaddr ($straddr);
		if ($ptrname == $straddr) $ptrname = "";	
		if (array_key_exists($i,$addresshash)) 
			$db_ptrname = $addresshash[$i];
		else  $db_ptrname = ""; 
		
		// remove record if it does not exist in DNS anymore
		if (strlen($ptrname) == 0 & strlen($db_ptrname) > 0) {
			updateAddress($ip_bin,"");
			syslog(LOG_INFO, $straddr. ": Removed PTR record ".$db_ptrname);	
		}
		// add new record which is not in Racktables yet
		if (strlen($ptrname) > 0 & strlen($db_ptrname) == 0) {
			updateAddress($ip_bin,$ptrname); 
			syslog(LOG_INFO, $straddr. ": Added PTR record ".$ptrname);
		}
		
		// update changed records in Racktables
		if (strlen($ptrname) > 0 & strlen($db_ptrname) > 0 & $db_ptrname <> $ptrname) {
			updateAddress($ip_bin,$ptrname);
			syslog(LOG_INFO, $straddr. ": Updated PTR record from ".$db_ptrname." to ".$ptrname);
	  	}
	}
}		

// Close syslog

syslog(LOG_INFO, "--- racktables_PTR_update.php finished ---");
closelog();
?>
