<?php
// Cost report for printing or "Save as PDF" in the browser: every recurring cost,
// per area and per household member, plus the price changes of the last 12 months.

require_once 'includes/header.php';
require_once 'includes/household_costs.php';
require_once 'includes/report_body.php';
?>

<?php if (empty($isReadOnlyAccount)) { include 'includes/report_share.php'; } ?>

<?php require_once 'includes/footer.php'; ?>
