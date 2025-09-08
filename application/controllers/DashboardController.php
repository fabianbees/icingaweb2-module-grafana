<?php

namespace Icinga\Module\Grafana\Controllers;

use Icinga\Module\Grafana\Web\Controller\IcingadbGrafanaController;

use ipl\Web\Url;

/**
 * DashboardController for showing graphs for Monitoring Module dashboards
 */
class DashboardController extends IcingadbGrafanaController
{
    public function init()
    {
        $this->assertPermission('grafana/graph');
        $this->setAutorefreshInterval(15);
    }

    public function indexAction()
    {
        // This is an old controller that was used in the Monitoring module.
        // So we redirect to IcingaDB, just to that older dashboards don't break.
        $this->redirectNow(Url::fromPath('grafana/icingadbdashboard')->setQueryString($this->params));
    }
}
