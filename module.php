<?php
declare(strict_types=1);
require __DIR__ . '/autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\CronjobModule;

$module = new CronjobModule();
$module->setEnabled(true);

return $module;
