<?php
session_start();

$lrn = $_GET['learner_id'] ?? $_GET['lrn'] ?? '';
$school_year = $_GET['school_year'] ?? $_GET['sy'] ?? '';
$quarter = $_GET['quarter'] ?? 'all';

$params = [];
if ($lrn !== '') {
    $params['learner_id'] = $lrn;
}
if ($school_year !== '') {
    $params['school_year'] = $school_year;
}
$params['quarter'] = $quarter;

header('Location: printAnnex2.php?' . http_build_query($params));
exit();
