<?php

$TRAC_WIKI_PAGE = 'Console_servers';

// Gets port info from a console server
function getSNMP_Console_Info ($host, $SNMP_community) {
	
	snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
	//  Avocent
	$brand = "avocent";
	$data = snmprealwalk($host, $SNMP_community, ".1.3.6.1.4.1.2925.4.2.6.2.1.1.3");
	 
	// Cyclades
	if (empty($data)) 
	{
		$brand= "cyclades";
		$data = snmprealwalk($host, $SNMP_community, ".1.3.6.1.4.1.10418.16.2.3.2.1.4");
	}
	
	if ($brand == "avocent") $index = 0;
	else $index = 1;
	foreach ($data as $key => $value) {
		$snmpdata[$index] = trim($value);
		$index++;
	}
	unset($data);
	if ($brand == "avocent") unset($snmpdata[0]); // delete port 0, it doesnt exist, but is returned by SNMP
	if ($brand == "cyclades") array_pop($snmpdata); // delete last entry, it doesnt exist, but is returned by SNMP
	return $snmpdata;
}

// XML-RPC Function
function do_call($url, $port, $request) {
 
    $header[] = "Content-type: text/xml";
    $header[] = "Content-length: ".strlen($request);
   
    $ch = curl_init();  
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

    $data = curl_exec($ch);      
    if (curl_errno($ch)) {
        print curl_error($ch);
    } else {
        curl_close($ch);
        return $data;
    }
}


function updateTracPage($url, $tracwiki_page)
{
	$tracTable = "\n||=Console server =||=Poort =||=Hostname =||\n";
	$result = usePreparedSelectBlade ("select Object.name as object_name, Port.name as port_name, Port.label from Object, Port where Port.object_id = Object.id and Object.objtype_id=1644 order by Object.name, ABS(port_name)");
	while ($row = $result->fetch (PDO::FETCH_ASSOC)) 
	{
		if (!preg_match("/^(FREE|VRIJ)/",$row['label']))
			$tracTable .= "|| " . $row['object_name'] . " || " . $row['port_name'] . " || " . $row['label']. "\n"; 
	}

	//Get current version of the Console_servers page from TracWiki
	$request = xmlrpc_encode_request('wiki.getPage',$tracwiki_page);
	$page = do_call($url, 443, $request);
	$page = xmlrpc_decode($page);
	// check if there was a valid console_table tag found
	if (strpos($page , "[=#console_table]") === false)
    {
        return "NO_TAG_FOUND";
    }
		
	// Check if there are any changes in the administration
	$current_console_table = substr($page,strpos($page , "[=#console_table]") + 17 , strpos($page , "[=#end_console_table]") - strpos ($page , "[=#console_table]") - 63);
	if ($current_console_table == $tracTable) 
	{
		return "NO_CHANGE";
	}
	// build new page
	date_default_timezone_set('Europe/Amsterdam');
	$tracTable .= "\nTabel laatst bijgewerkt: " . date('Y-m-d H:i:s') ."\n";
	$page_top = substr($page,0, strpos ($page , "[=#console_table]") + 17);
	$page_bottom = substr($page,strpos($page , "[=#end_console_table]"), strlen($page));
	$new_page = $page_top . $tracTable . $page_bottom;
	
	// post new page
	$request = xmlrpc_encode_request('wiki.putPage', array ($tracwiki_page, $new_page, array ( "bla" => 0)));
	$result = do_call($url, 443, $request);
	return "OK";
}

function updateRackTables($snmphash)
{
	foreach ($snmphash as $console_server => $portdata) 
	{
		// read the complete list of ports from the Racktablesdatabase
		$result = usePreparedSelectBlade ("select Object.id as object_id, Port.* from Object INNER JOIN Port ON Port.object_id = Object.id where Object.objtype_id=1644 and Object.name='".$console_server."';");
		while ($row = $result->fetch (PDO::FETCH_ASSOC)) 
		{
			$ports[$console_server][$row['name']] = $row;
		}
		$object_id = lookupEntityByString ('object', $console_server);

		// Loop through ports array and add db data to snmp table
		foreach ($ports[$console_server] as $dbport) 
		{
			$portdata[$dbport['name']] = array( 'snmp' => $portdata[$dbport['name']] , 'db' => $dbport['label']);
		}

		// Loop through SNMP data and matches changes between db and SNMP
		foreach ($portdata as $port => $label) 
		{	
			if (!array_key_exists('db',$label))
			{
				$new_port_id = commitAddPort ( $object_id, trim ($port), 24, trim($label), "" );
			}
			else if ($label['snmp'] !== $label['db']) 
			{
				commitUpdatePort ($ports[$console_server][$port]['object_id'], $ports[$console_server][$port]['id'], $port, 24, $label['snmp'], "", "");
			} 
		}
	}
}

/* --- MAIN --- */

// Call neccesary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include '/var/www/racktables-dev/inc/init.php';


global $RPC_URL;   // Contains RPC user and password, is defined in secret.php

// Open syslog
openlog('Console_Port_check', LOG_PID | LOG_ODELAY,LOG_LOCAL7);
syslog(LOG_INFO, "--- racktables_Console_Port_update.php started ---");

// Make a list of all console server objects
$result = usePreparedSelectBlade ("select Object.id, Object.name ,AttributeValue.string_value as community from Object 
									LEFT JOIN AttributeValue ON AttributeValue.object_id = Object.id 
									LEFT JOIN Dictionary ON Object.objtype_id = Dictionary.dict_key 
									LEFT JOIN Attribute ON AttributeValue.attr_id=Attribute.id
									where Dictionary.dict_value=\"serial console server\"  and Attribute.name=\"SNMP Community\";");
while ($row = $result->fetch (PDO::FETCH_ASSOC)) {
	$consoles[]=$row;
}

// Retrieve port info for all consoles using SNMP
foreach ($consoles as $console)
{
	$SNMP_Console_Info = getSNMP_Console_Info($console['name'], $console['community']);
	if (count($SNMP_Console_Info) > 0)
	{
		$snmphash[$console['name']] = $SNMP_Console_Info;
	}
	else 
	{
		syslog(LOG_INFO, "No info received from host: ".$console['name']);
	}
}

updateRackTables($snmphash);

$result = updateTracPage($RPC_URL, $TRAC_WIKI_PAGE);
if ($result == "NO_TAG_OFUND")
{
	syslog(LOG_INFO, "No [=#console_table] tag detected, wikipage $TRAC_WIKI_PAGE not updated");
}
if ($result == "NO_CHANGE")
{
	syslog(LOG_INFO, "No changes detected, wikipage $TRAC_WIKI_PAGE not updated");
}
if ($result == "OK")
{
	syslog(LOG_INFO, "wikipage: $TRAC_WIKI_PAGE updated");
}

// Close syslog
syslog(LOG_INFO, "--- racktables_Console_Port_update.php finished ---");
closelog();

?>
