<?php
$basedir = "/data/racktables/prod";
# Load server report
require_once $basedir."/wwwroot/extensions/reports/server-report.php";

# Load virtual machine report
#require_once "../wwwroot/extensions/reports/vm-report.php";

# Load switch report
require_once $basedir."/wwwroot/extensions/reports/switch-report.php";

# Load custom report
require_once $basedir."/wwwroot/extensions/reports/custom-report.php";

?>
