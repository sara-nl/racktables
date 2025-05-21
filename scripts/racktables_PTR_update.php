<?php
// racktables_PTR_update.php
//
// Script which supports Racktables to update PTR records in the IPv4 Space page.
//
// (c) 2013 Erik Ruiter
// erik.ruiter@surfsara.nl
//
// CHANGELOG
//
// 27-3-25: Replaced syslog config for writing to stdout and logfile
// 3-4-25: Removed double loops
// 5-5-2025: Small refactor, merge networks to remove duplicates
// 5-5-2025: Small refactor, split large ranges to avoid database connection errors

// Call necessary Racktables parameters and libraries
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
$script_mode = TRUE;
include dirname(__FILE__).'/../wwwroot/inc/init.php';
date_default_timezone_set('Europe/Amsterdam');
// Initialize log file path once per script run
$log_dir = getenv('RACKTABLES_LOG_DIR') ?: '/tmp';
$day_of_week = date('l'); // e.g., Monday
$log_file = "$log_dir/racktables_PTR_update_$day_of_week.log";

// Clear (overwrite) the log file at the beginning of the script
file_put_contents($log_file, '');

// Log function to echo and write to log file
function log_message($message) {
    global $log_file;
    $timestamp = date('d/M/Y:H:i:s O');
    $formatted_message = "[$timestamp] $message\n";

    // Echo to stdout
    echo $formatted_message;

    // Append to log file
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

log_message('racktables_PTR_update.php started');

// Get all address records
$result = usePreparedSelectBlade('select * from IPv4Address;');
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $addresses[] = $row;
}
// Close the database connection https://www.php.net/manual/en/pdo.connections.php)
$result = null;

$addresshash = [];
foreach ($addresses as $address) {
    $addresshash[$address['ip']] = $address['name'];
}

// Read the complete list of networks from the database
$result = usePreparedSelectBlade('select * from IPv4Network;');
// Fetch all the records at once
$networks = $result->fetchAll(PDO::FETCH_ASSOC);
// Close the database connection https://www.php.net/manual/en/pdo.connections.php)
$result = null;

log_message("Merging overlapping IP rangens, split large ranges if exceeding 1 million addresses");

// Normalize and merge networks
$raw_ranges = [];
foreach ($networks as $net_id) {
    // https://wiki.racktables.org/index.php/RackTablesDevelGuide#function_spotEntity_.28.24realm.2C_.24id.29
    $net = spotEntity('ipv4net', $net_id['id']);
    $startip = ip4_bin2int($net['ip_bin']);
    $size = pow(2, 32 - $net['mask']);
    $endip = $startip + $size - 1;
    $raw_ranges[] = ['start' => $startip, 'end' => $endip];
}

// Sort by start IP
usort($raw_ranges, fn($a, $b) => $a['start'] <=> $b['start']);

// Merge overlapping blocks
$merged = [];
foreach ($raw_ranges as $range) {
    if (empty($merged)) {
        $merged[] = $range;
        continue;
    }

    $last = &$merged[count($merged) - 1];
    if ($range['start'] <= $last['end'] + 1) {
        $last['end'] = max($last['end'], $range['end']);
    } else {
        $merged[] = $range;
    }
}

// Split large blocks (> 2^20 IPs)
$final_ranges = [];
foreach ($merged as $range) {
    $start = $range['start'];
    $end = $range['end'];
    while ($start <= $end) {
        $max_block = min(1048576, $end - $start + 1);
        $final_ranges[] = ['start' => $start, 'end' => $start + $max_block - 1];
        $start += $max_block;
    }
}

// Sort, move RFC1918 blocks to the end in specific order: 192.168.0.0/16, then 172.16.0.0/12 finally 10.0.0.0/8
// Entries in public ranges should be updated first
usort($final_ranges, function ($a, $b) {
    $get_priority = function ($range) {
        $start = $range['start'];

        if ($start >= 167772160 && $start <= 184549375) {
            // 10.0.0.0/8
            return 3;
        } elseif ($start >= 2886729728 && $start <= 2887778303) {
            // 172.16.0.0/12
            return 2;
        } elseif ($start >= 3232235520 && $start <= 3232301055) {
            // 192.168.0.0/16
            return 1;
        }

        // All other IPs = public
        return 0;
    };

    $prio_a = $get_priority($a);
    $prio_b = $get_priority($b);

    // Sort by priority, then by start IP
    return [$prio_a, $a['start']] <=> [$prio_b, $b['start']];
});

// Process ranges
foreach ($final_ranges as $range) {
    $startip = $range['start'];
    $endip = $range['end'];
    $strstartip = long2ip($startip);
    $strendip = long2ip($endip);
    $ip_count = $endip - $startip + 1;

    log_message("Starting with range $strstartip - $strendip ($ip_count IPs)");

    $milestones = null;
    if ($ip_count > 1000000) {
        log_message("Large range detected, logging every 3%");
        $milestones = range(3, 99, 3);
    }

    $logged_percentages = [];

    for ($i = $startip; $i <= $endip; $i++) {
        // Do PTR lookup
        $ip_bin  = ip4_int2bin($i);
        $straddr = ip4_format($ip_bin);
        // gethostbyaddr only returns one result
        // Double PTRs are not caught
        $ptrname = gethostbyaddr($straddr);

        // Get current PTR record in RackTables, or empty string if none exists
        $db_ptrname = $addresshash[$i] ?? '';

        // Determine if DNS has a valid PTR record for this IP
        $ip_has_ptr = strlen($ptrname) > 0 && $ptrname !== $straddr;

        // updateAddress is a function from database.php
        // function updateAddress ($ip_bin, $name = '', $reserved = 'no', $comment)
        $update_timestamp = date('d-m-Y H:i:s');
        if ($db_ptrname && !$ip_has_ptr) {
            // PTR record exists in RackTables, but not in DNS anymore — remove it
            $comment = "Removed on $update_timestamp (was $db_ptrname)";
            updateAddress($ip_bin, '', NULL, $comment);
            log_message("$straddr: Removed PTR record $db_ptrname");

        } elseif (!$db_ptrname && $ip_has_ptr) {
            // PTR record exists in DNS, but not in RackTables — add it
            $comment = "Added on $update_timestamp";
            updateAddress($ip_bin, $ptrname, NULL, $comment);
            log_message("$straddr: Added PTR record $ptrname");

        } elseif ($db_ptrname && $ip_has_ptr && $db_ptrname !== $ptrname) {
            // PTR record exists in both but values differ — update it
            $comment = "Updated on $update_timestamp (previous $db_ptrname)";
            updateAddress($ip_bin, $ptrname, NULL, $comment);
            log_message("$straddr: Updated PTR record from $db_ptrname to $ptrname");
        }

        // If it is a large range, log only once per $milestone
        if ($milestones !== null) {
            // Calculate the percentage of the loop completed
            $percentage = (($i - $startip) / $ip_count) * 100;
            // Round float
            $rounded = round($percentage);
            if (in_array($rounded, $milestones) && !isset($logged_percentages[$rounded])) {
                $logged_percentages[$rounded] = true;
                log_message("Reached $rounded% of $strstartip - $strendip");
            }
        }
    }

    log_message("Finished with range $strstartip - $strendip");
}

log_message('racktables_PTR_update.php finished');

?>
