<?php

namespace Icinga\Module\Grafana\Controllers;

use Icinga\Module\Grafana\ProvidedHook\Icingadb\HostDetailExtension;
use Icinga\Module\Grafana\ProvidedHook\Icingadb\ServiceDetailExtension;
use Icinga\Module\Grafana\Web\Controller\IcingadbGrafanaController;

use Icinga\Application\Config;
use Icinga\Module\Grafana\Helpers\Timeranges;
use Icinga\Module\Icingadb\Model\CustomvarFlat;
use Icinga\Module\Icingadb\Model\Service;

use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\HtmlString;
use ipl\Stdlib\Filter;

class IcingadbshowController extends IcingadbGrafanaController
{
    protected $showFullscreen;
    protected $host;
    protected $custvardisable = 'grafana_graph_disable';
    protected $config;
    protected $object;

    public function init()
    {
        $this->assertPermission('grafana/showall');
        $this->view->showFullscreen
            = $this->showFullscreen
            = (bool)$this->_helper->layout()->showFullscreen;
        $this->host = $this->getParam('host');
        $this->config = Config::module('grafana')->getSection('grafana');

        // Name of the custom variable to disable graph
        $this->custvardisable = ($this->config->get('custvardisable', $this->custvardisable));
    }

    public function indexAction()
    {
        $this->disableAutoRefresh();

        $this->addControl(
            HtmlElement::create(
                'h1',
                null,
                sprintf($this->translate('Performance graphs for %s'), $this->host)
            )
        );

        // Preserve timerange if selected
        $parameters = ['host' => $this->host];
        if ($this->hasParam('timerange')) {
            $parameters['timerange'] = $this->getParam('timerange');
        }

        $menu = new Timeranges($parameters, 'grafana/icingadbshow');
        $this->addControl(new HtmlString($menu->getTimerangeMenu()));

        // First host object for host graph
        $this->object = $this->getHostObject($this->host);
        $varsFlat = CustomvarFlat::on($this->getDb());
        $this->applyRestrictions($varsFlat);

        $varsFlat
            ->columns(['flatname', 'flatvalue'])
            ->orderBy('flatname');
        $varsFlat->filter(Filter::equal('host.id', $this->object->id));

        $customVars = $this->getDb()->fetchPairs($varsFlat->assembleSelect());

        if ($this->object->perfdata_enabled == "y"
            || !(isset($customVars[$this->custvardisable])
                && json_decode(strtolower($customVars[$this->custvardisable])) !== false)
        ) {
            $object = (new HtmlDocument())
                ->addHtml(HtmlElement::create('h2', null, $this->object->checkcommand_name));
            $this->addContent($object);
            $this->addContent((new HostDetailExtension())->getPreviewHtml($this->object, true));
        }

        // Get all services for this host
        $query = Service::on($this->getDb())->with([
            'state',
            'icon_image',
            'host',
            'host.state'
        ]);

        $query->filter(Filter::equal('host.name', $this->host));

        $this->applyRestrictions($query);

        foreach ($query as $service) {
            $this->object = $this->getServiceObject($service->name, $this->host);
            $varsFlat = CustomvarFlat::on($this->getDb());
            $this->applyRestrictions($varsFlat);

            $varsFlat
                ->columns(['flatname', 'flatvalue'])
                ->orderBy('flatname');
            $varsFlat->filter(Filter::equal('service.id', $service->id));
            $customVars = $this->getDb()->fetchPairs($varsFlat->assembleSelect());

            if ($this->object->perfdata_enabled == "y"
                && !(isset($customVars[$this->custvardisable])
                    && json_decode(strtolower($customVars[$this->custvardisable])) !== false)
            ) {
                $object = (new HtmlDocument())
                    ->addHtml(HtmlElement::create('h2', null, $service->name));
                $this->addContent($object);
                $this->addContent((new ServiceDetailExtension())->getPreviewHtml($service, true));
            }
        }

        unset($this->object);
        unset($customVars);
    }
}
