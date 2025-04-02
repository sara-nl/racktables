<?php

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

// Log the start of the script
log_message('racktables_Port_update.php started');

function getSNMP_IF_Info($host, $SNMP_community) {
    // get SNMP info
    snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);
    $b = snmprealwalk($host, $SNMP_community, "ifEntry");
    $c = snmprealwalk($host, $SNMP_community, "ifAlias");

    $a = array_merge($b, $c);

    foreach ($a as $key => $value) {
        $oidarray = explode('.', substr($key, strpos($key, ':') + 2));
        $ifIndex = $oidarray[1];
        $oid = $oidarray[0];
        $oidvalue = substr($value, strpos($value, ':') + 2);
        $snmphash[$ifIndex][$oid] = trim($oidvalue);
    }

    // Remove unwanted interfaces
    $regstring = "(pfe|pfh|demux|cbp|fxp|lsi|pip|pp|dsc|tap|gre|ipip|pime|pimd|mtun|16384|16385|32767|32769|ud-|ip-|gr-|pd-|pe-|vt-|mt-|lt-|lc-)";
    foreach ($snmphash as $key => $value) {
        if (preg_match($regstring, $value['ifDescr'])) {
            unset($snmphash[$key]);
        }
    }

    // Parse MAC address
    foreach ($snmphash as $key => $value) {
        $ifPhysAddressArray = explode(":", $value['ifPhysAddress']);
        $ifPhysAddress = "";
        foreach ($ifPhysAddressArray as $byte) {
            if (strlen($byte) == 1) $byte = "0" . $byte;
            $ifPhysAddress = $ifPhysAddress . $byte;
        }
        $snmphash[$key]['ifPhysAddress'] = $ifPhysAddress;
    }
    return $snmphash;
}

function getSNMP_IP_Info($host, $SNMP_community) {
    // get SNMP info
    snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);
    $a = snmprealwalk($host, $SNMP_community, "ipAdEntIfIndex");

    foreach ($a as $key => $value) {
        $oidarray = explode('.', substr($value, strpos($value, ':') + 2));
        $ifIndex = $oidarray[0];
        $oidvalue = substr($key, strpos($key, '.') + 1);
        $oidvaluearray = explode('.', $oidvalue);
        if (sizeof($oidvaluearray) > 4) {
            unset($oidvaluearray[0]);
            $oidvalue = implode(".", $oidvaluearray);
        }

        $snmphash[$ifIndex] = array('ipAdEntAddr' => $oidvalue);
    }
    return $snmphash;
}

function recursive_array_search($needle, $haystack) {
    foreach ($haystack as $key => $value) {
        $current_key = $key;
        if ($needle === $value OR (is_array($value) && recursive_array_search($needle, $value))) {
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

$result = usePreparedSelectBlade("select Object.id, Object.name ,AttributeValue.string_value from Object
                        LEFT JOIN AttributeValue ON AttributeValue.object_id = Object.id
                        LEFT JOIN Dictionary ON Object.objtype_id = Dictionary.dict_key
                        LEFT JOIN Attribute ON AttributeValue.attr_id = Attribute.id
                        where Dictionary.dict_value=\"Router\"  and Attribute.name=\"SNMP Community\";");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $routers[] = $row;
}

foreach ($routers as $router) {
    $iphash = getSNMP_IP_Info($router['name'], $router['string_value']);
    $snmphash = getSNMP_IF_Info($router['name'], $router['string_value']);
    foreach ($iphash as $key => $value) {
        if (array_key_exists($key, $snmphash)) {
            $snmphash[$key]['ipAdEntAddr'] = $value['ipAdEntAddr'];
        }
    }

    // Read ports
    $result = usePreparedSelectBlade('select Object.*, Port.* from Object LEFT JOIN Port ON Port.object_id = Object.id where Object.objtype_id=7 and Object.id=' . $router['id'] . ';');
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $ports[] = $row;
    }
    $result = usePreparedSelectBlade('select * from IPv4Allocation where object_id=' . $router['id'] . ';');
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $ip_allocs[] = $row;
    }

    $snmpallocindex = 1;

    foreach ($snmphash as $key => $value) {

        // Update ports
        $ifDescr = $value['ifDescr'];
        $portexists = FALSE;
        foreach ($ports as $port) {
            if ($ifDescr == $port['name']) {
                $portexists = TRUE;
                if ($value['ifAlias'] != $port['label']) {
                    usePreparedUpdateBlade('Port', array('label' => $value['ifAlias']), array('name' => $port['name'], 'object_id' => $router['id'], 'type' => 24));
                    log_message($router['name'] . ": " . $port['name'] . " FROM: " . $port['label'] . " TO: " . $value['ifAlias'] . " changed in database");
                }
                if ($value['ifPhysAddress'] != $port['l2address']) {
                    usePreparedUpdateBlade('Port', array('l2address' => $value['ifPhysAddress']), array('name' => $port['name'], 'object_id' => $router['id'], 'type' => 24));
                    log_message($router['name'] . ": " . $port['name'] . " FROM: " . $port['l2address'] . " TO: " . $value['ifPhysAddress'] . " changed in database");
                }
            }
        }
        if ($portexists == FALSE) {
            usePreparedInsertBlade('Port', array('object_id' => $router['id'], 'name' => $ifDescr, 'label' => $value['ifAlias'], 'iif_id' => 1, 'type' => 24, 'l2address' => $value['ifPhysAddress']));
            log_message($router['name'] . ": " . $ifDescr . " added to database");
        }

        // Build IP allocations hash
        if (strlen($value['ipAdEntAddr']) > 0) {
            $snmp_allocs[$snmpallocindex] = array('object_id' => $router['id'], 'ip' => $value['ipAdEntAddr'], 'name' => $ifDescr);
            $snmpallocindex++;
        }
    }
    foreach ($ports as $port) {
        if (recursive_array_search($port['name'], $snmphash) == FALSE) {
            usePreparedDeleteBlade('Port', array('object_id' => $router['id'], 'name' => $port['name'], 'type' => 24));
            log_message($router['name'] . ": " . $port['name'] . " " . $port['label'] . " removed from database");
        }
    }

    // Check and update IP allocations
    foreach ($snmp_allocs as $snmp_alloc) {

        $alloc_status = "UNKNOWN";
        foreach ($ip_allocs as $ip_alloc) {
            $ip_bin = ip4_int2bin($ip_alloc['ip']);
            $ip = ip4_format($ip_bin);

            if ($snmp_alloc['ip'] == $ip) {
                $alloc_status = "FOUND";
                if ($snmp_alloc['name'] != $ip_alloc['name']) {
                    usePreparedUpdateBlade('IPv4Allocation', array('name' => $snmp_alloc['name']), array('ip' => $ip_alloc['ip'], 'name' => $ip_alloc['name']));
                    log_message($router['name'] . ": " . $snmp_alloc['ip'] . " FROM: " . $ip_alloc['name'] . " TO: " . $snmp_alloc['name'] . " changed in database");
                }
            }
        }
        if ($alloc_status == "UNKNOWN") {
            $int_ip = ip4_bin2int(ip4_parse($snmp_alloc['ip']));
            usePreparedInsertBlade('IPv4Allocation', array('object_id' => $router['id'], 'ip' => $int_ip, 'name' => $snmp_alloc['name'], 'type' => 'router'));
            log_message($router['name'] . ": " . $snmp_alloc['ip'] . " added to database");
        }
    }

    // Loop again to remove unused IP allocations
    foreach ($ip_allocs as $ip_alloc) {
        $ip_bin = ip4_int2bin($ip_alloc['ip']);
        $ip = ip4_format($ip_bin);

        if (recursive_array_search($ip, $snmp_allocs) == FALSE) {
            usePreparedDeleteBlade('IPv4Allocation', array('ip' => $ip));
            log_message($router['name'] . ": " . $ip . " removed from database");
        }
    }
}

// Log the end of the script
log_message('racktables_Port_update.php completed');

?>
