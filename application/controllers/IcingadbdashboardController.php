<?php

namespace Icinga\Module\Grafana\Controllers;

use Icinga\Module\Grafana\ProvidedHook\Icingadb\HostDetailExtension;
use Icinga\Module\Grafana\ProvidedHook\Icingadb\ServiceDetailExtension;
use Icinga\Module\Grafana\Web\Controller\IcingadbGrafanaController;

use Icinga\Module\Icingadb\Model\Host;

use ipl\Web\Url;

class IcingadbdashboardController extends IcingadbGrafanaController
{
    public function init()
    {
        $this->assertPermission('grafana/graph');
        $this->setAutorefreshInterval(15);
    }

    public function indexAction()
    {
        if (! $this->useIcingadbAsBackend) {
            $this->redirectNow(Url::fromPath('grafana/dashboard')->setQueryString($this->params));
        }

        $this->getTabs()->add(
            'graphs',
            [
                'active' => true,
                'label' => $this->translate('Graphs'),
                'url' => $this->getRequest()->getUrl()
            ]
        );

        $hostName = $this->params->getRequired('host');
        $serviceName = $this->params->get('service');

        if ($serviceName != null) {
            $object = $this->getServiceObject($serviceName, $hostName);
        } else {
            $object = $this->getHostObject($hostName);
        }

        if ($object instanceof Host) {
            $graph = new HostDetailExtension();
        } else {
            $graph = new ServiceDetailExtension();
        }

        $this->addContent($graph->getPreviewHtml($object));
    }
}
