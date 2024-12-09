<?php

namespace Icinga\Module\Grafana\Web\Controller;

use Icinga\Module\Grafana\ProvidedHook\Icingadb\IcingadbSupport;

use Icinga\Application\Modules\Module;
use Icinga\Module\Icingadb\Common\Auth;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Icingadb\Model\Host;
use Icinga\Module\Icingadb\Model\Service;

use ipl\Web\Compat\CompatController;
use ipl\Stdlib\Filter;

class IcingadbGrafanaController extends CompatController
{
    use Auth;
    use Database;

    /** @var bool Whether to use icingadb as the backend */
    protected $useIcingadbAsBackend;

    protected function moduleInit()
    {
        $this->useIcingadbAsBackend = Module::exists('icingadb') && IcingadbSupport::useIcingaDbAsBackend();
    }

    /**
     * Returns the Host object from the database given the hostname
     * @param string $host host name for the object
     * @return Host
     */
    public function getHostObject(string $host): Host
    {
        $query = Host::on($this->getDb())->with([
            'state',
            'icon_image'
        ]);

        $query->filter(Filter::equal('name', $host));

        $this->applyRestrictions($query);

        $host = $query->first();

        if ($host === null) {
            throw new NotFoundError(t('Host not found'));
        }

        return $host;
    }

    /**
     * Returns the Service object from the database given the hostname/servicename
     * @param string $service service name for the object
     * @param string $host host name for the object
     * @return Service
     */
    public function getServiceObject(string $service, string $host): Service
    {
        $query = Service::on($this->getDb())->with([
            'state',
            'icon_image',
            'host',
            'host.state'
        ]);

        $query->filter(Filter::equal('name', $service));
        $query->filter(Filter::equal('host.name', $host));

        $this->applyRestrictions($query);

        $service = $query->first();

        if ($service === null) {
            throw new NotFoundError(t('Service not found'));
        }

        return $service;
    }
}
