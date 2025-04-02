<?php
// racktables_PTR_update.php
//
// Script which supports Racktables to update PTR records in the IPv4 Space page.
//
// (c) 2013 Erik Ruiter
// erik.ruiter@surfsara.nl
//
// CHANGELOG
// 27-3-25: Replaced syslog config for writing to stdout and logfile

// Call necessary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include dirname(__FILE__).'/../wwwroot/inc/init.php';

// Log function to echo and write to log file
function log_message($message) {
    $timestamp = date('d/M/Y:H:i:s O');
    $formatted_message = "[$timestamp] $message\n";

    // Echo to stdout
    echo $formatted_message;

    // Write to log file
    $log_dir = getenv('RACKTABLES_LOG_DIR') ?: '/tmp';
    file_put_contents("$log_dir/racktables_Console_Port_update.log", $formatted_message, FILE_APPEND);
}

log_message('racktables_PTR_update.php started');

// Select the complete list of IPv4 addresses and names from the database
$result = usePreparedSelectBlade('select * from IPv4Address;');
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $addresses[] = $row;
}

// Build a key/value hash
foreach ($addresses as $address) {
    $addresshash[$address['ip']] = $address['name'];
}

// Read the complete list of networks from the database
$result = usePreparedSelectBlade('select * from IPv4Network;');
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $data[] = $row;
}

foreach ($data as $net_id) {

    // Retrieve detailed info for the network from Racktables
    $net = spotEntity('ipv4net', $net_id['id']);
    $startip = ip4_bin2int($net['ip_bin']);
    $endip = $startip + pow(2, 32 - $net['mask']) - 1;

    // Check all PTR records in the network from the first to the last address
    for ($i = $startip; $i <= $endip; $i++) {

        // Do PTR lookup
        $ip_bin = ip4_int2bin($i);
        $straddr = ip4_format($ip_bin);
        $ptrname = gethostbyaddr($straddr);
        if ($ptrname == $straddr) $ptrname = "";
        if (array_key_exists($i, $addresshash))
            $db_ptrname = $addresshash[$i];
        else
            $db_ptrname = "";

        // Remove record if it does not exist in DNS anymore
        if (strlen($ptrname) == 0 && strlen($db_ptrname) > 0) {
            updateAddress($ip_bin, $ptrname, NULL, false);
            log_message("$straddr: Removed PTR record $db_ptrname");
        }
        // Add new record which is not in Racktables yet
        if (strlen($ptrname) > 0 && strlen($db_ptrname) == 0) {
            updateAddress($ip_bin, $ptrname, NULL, false);
            log_message("$straddr: Added PTR record $ptrname");
        }

        // Update changed records in Racktables
        if (strlen($ptrname) > 0 && strlen($db_ptrname) > 0 && $db_ptrname != $ptrname) {
            updateAddress($ip_bin, $ptrname, NULL, false);
            log_message("$straddr: Updated PTR record from $db_ptrname to $ptrname");
        }
    }
}

log_message('racktables_PTR_update.php finished');
?>
