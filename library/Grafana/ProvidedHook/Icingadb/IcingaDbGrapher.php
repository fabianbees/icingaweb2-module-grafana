<?php

namespace Icinga\Module\Grafana\ProvidedHook\Icingadb;

use Icinga\Module\Grafana\Helpers\Timeranges;
use Icinga\Module\Grafana\Helpers\Util;

use Icinga\Application\Config;
use Icinga\Application\Icinga;
use Icinga\Authentication\Auth;
use Icinga\Exception\ConfigurationError;
use Icinga\Module\Icingadb\Common\Auth as IcingaDbAuth;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Icingadb\Common\Links;
use Icinga\Module\Icingadb\Model\CustomvarFlat;
use Icinga\Module\Icingadb\Model\Host;
use Icinga\Module\Icingadb\Model\Service;

use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\HtmlString;
use ipl\Html\ValidHtml;
use ipl\Orm\Model;
use ipl\Stdlib\Filter;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/**
 * IcingaDbGrapher contains methods for retrieving and rendering the data from Grafana
 */
trait IcingaDbGrapher
{
    use Database;
    use IcingaDbAuth;

    // Could be constants in the future, but for now we want to keep compatibility
    protected $GRAFANA_URL = "%s://%s/d/%s/%s?var-hostname=%s&var-service=%s&var-command=%s%s&from=%s&to=%s&orgId=%s&viewPanel=%s";
    protected $GRAFANA_URL_SOLO = "%s://%s/d-solo/%s/%s?var-hostname=%s&var-service=%s&var-command=%s%s&panelId=%s&orgId=%s&theme=%s&from=%s&to=%s";

    protected $config;
    protected $graphConfig;
    protected $auth;
    protected $authentication;
    protected $grafanaHost = null;
    protected $grafanaTheme = null;
    protected $protocol = "http";
    protected $usePublic = "no";
    protected $publicHost = null;
    protected $publicProtocol = "http";
    protected $timerange = "6h";
    protected $timerangeto = "now";
    protected $username = null;
    protected $password = null;
    protected $apiToken = null;
    protected $width = 640;
    protected $height = 280;
    protected $defaultDashboard = "icinga2-default";
    protected $defaultDashboardPanelId = "1";
    protected $defaultOrgId = "1";
    protected $shadows = false;
    protected $dataSource = null;
    protected $accessMode = "proxy";
    protected $proxyTimeout = "5";
    protected $refresh = "no";
    protected $title;  //"<h2>Performance Graph</h2>";
    protected $custvardisable = "grafana_graph_disable";
    protected $custvarconfig = "grafana_graph_config";
    protected $repeatable = "no";
    protected $numberMetrics = "1";
    protected $SSLVerifyPeer = false;
    protected $SSLVerifyHost = "0";
    protected $cacheTime = 300;
    protected $defaultdashboarduid;
    protected $object;
    protected $permission;
    protected $dashboard;
    protected $dashboarduid;
    protected $panelId;
    protected $orgId;
    protected $customVars;
    protected $pngUrl;

    protected function init()
    {
        $this->title = Html::tag("h2", "Performance Graph");
        $this->permission = Auth::getInstance();
        $this->config = Config::module('grafana')->getSection('grafana');

        $this->grafanaHost = $this->config->get('host', $this->grafanaHost);
        if ($this->grafanaHost === null) {
            throw new ConfigurationError(
                'No Grafana host configured!'
            );
        }

        // Set the protocol and host
        $this->protocol = $this->config->get('protocol', $this->protocol);
        $this->publicHost = $this->grafanaHost;
        $this->publicProtocol = $this->protocol;

        // Override protocol and host if usePublic is set
        $this->usePublic = $this->config->get('usepublic', $this->usePublic);
        if ($this->usePublic === 'yes') {
            $this->publicHost = $this->config->get('publichost', $this->publicHost);
            $this->publicProtocol = $this->config->get('publicprotocol', $this->publicProtocol);
            if ($this->publicHost === null) {
                throw new ConfigurationError(
                    'No Grafana public host configured!'
                );
            }
        }

        // Confid needed for Grafana
        $this->defaultDashboard = $this->config->get('defaultdashboard', $this->defaultDashboard);
        $this->defaultdashboarduid = $this->config->get('defaultdashboarduid', null);
        if (is_null($this->defaultdashboarduid)) {
            throw new ConfigurationError(
                'No UID for default dashboard found!'
            );
        }

        $this->defaultDashboardPanelId = $this->config->get(
            'defaultdashboardpanelid',
            $this->defaultDashboardPanelId
        );

        $this->defaultOrgId = $this->config->get('defaultorgid', $this->defaultOrgId);
        $this->grafanaTheme = Util::getUserThemeMode(Auth::getInstance()->getUser());
        $this->height = $this->config->get('height', $this->height);
        $this->width = $this->config->get('width', $this->width);

        $this->accessMode = $this->config->get('accessmode', $this->accessMode);
        $this->proxyTimeout = $this->config->get('proxytimeout', $this->proxyTimeout);

        $this->timerange = $this->config->get('timerange', $this->timerange);
        $this->dataSource = $this->config->get('datasource', $this->dataSource);
        $this->shadows = $this->config->get('shadows', $this->shadows);
        $this->custvardisable = ($this->config->get('custvardisable', $this->custvardisable));
        $this->custvarconfig = ($this->config->get('custvarconfig', $this->custvarconfig));

        $this->SSLVerifyHost = ($this->config->get('ssl_verifyhost', $this->SSLVerifyHost));
        $this->SSLVerifyPeer = ($this->config->get('ssl_verifypeer', $this->SSLVerifyPeer));

        // Username & Password or token
        $this->apiToken = $this->config->get('apitoken', $this->apiToken);
        $this->authentication = $this->config->get('authentication');
        if ($this->apiToken === null && $this->authentication === "token") {
            throw new ConfigurationError(
                'API token usage configured, but no token given!'
            );
        } else {
            $this->username = $this->config->get('username', $this->username);
            $this->password = $this->config->get('password', $this->password);
            if ($this->username !== null) {
                if ($this->password !== null) {
                    $this->auth = $this->username . ":" . $this->password;
                } else {
                    $this->auth = $this->username;
                }
            } else {
                $this->auth = "";
            }
        }
    }

    public function has(Model $object): bool
    {
        if (($object instanceof Host) || ($object instanceof Service)) {
            return true;
        } else {
            return false;
        }
    }

    private function getGraphConfigOption($section, $option, $default = null)
    {
        $value = $this->graphConfig->get($section, $option, $default);
        if (empty($value)) {
            return $default;
        }
        return $value;
    }

    /**
     * @param $serviceName
     * @param $serviceCommand
     * @return $this|null
     */
    private function getGraphConf($serviceName, $serviceCommand = null): ?self
    {
        $this->graphConfig = Config::module('grafana', 'graphs');

        if ($this->graphConfig->hasSection(strtok($serviceName, ' '))
            && ($this->graphConfig->hasSection($serviceName) === false)) {
            $serviceName = strtok($serviceName, ' ');
        }
        if ($this->graphConfig->hasSection(strtok($serviceName, ' ')) === false
            && ($this->graphConfig->hasSection($serviceName) === false)) {
            $serviceName = $serviceCommand;
            if ($this->graphConfig->hasSection($serviceCommand) === false && $this->defaultDashboard === 'none') {
                return null;
            }
        }

        $this->dashboard = $this->getGraphConfigOption($serviceName, 'dashboard', $this->defaultDashboard);
        $this->dashboarduid = $this->getGraphConfigOption($serviceName, 'dashboarduid', $this->defaultdashboarduid);
        $this->panelId = $this->getGraphConfigOption($serviceName, 'panelId', $this->defaultDashboardPanelId);
        $this->orgId = $this->getGraphConfigOption($serviceName, 'orgId', $this->defaultOrgId);
        $this->customVars = $this->getGraphConfigOption($serviceName, 'customVars', '');

        if (Url::fromRequest()->hasParam('tr-from') && Url::fromRequest()->hasParam('tr-to')) {
            $this->timerange = urldecode(Url::fromRequest()->getParam('tr-from'));
            $this->timerangeto = urldecode(Url::fromRequest()->getParam('tr-to'));
        } else {
            $this->timerange = Url::fromRequest()->hasParam('timerange') ?
                'now-' . urldecode(Url::fromRequest()->getParam('timerange')) :
                'now-' . $this->getGraphConfigOption($serviceName, 'timerange', $this->timerange);
            $this->timerangeto = strpos($this->timerange ?? '', '/') ? $this->timerange : $this->timerangeto;
        }

        $this->height = $this->getGraphConfigOption($serviceName, 'height', $this->height);
        $this->width = $this->getGraphConfigOption($serviceName, 'width', $this->width);
        $this->repeatable = $this->getGraphConfigOption($serviceName, 'repeatable', $this->repeatable);
        $this->numberMetrics = $this->getGraphConfigOption($serviceName, 'nmetrics', $this->numberMetrics);

        return $this;
    }

    /**
     * @param $serviceName
     * @param $hostName
     * @param HtmlDocument $previewHtml
     * @return bool
     * @throws \Icinga\Exception\ProgrammingError
     */
    private function getMyPreviewHtml($serviceName, $hostName, HtmlDocument $previewHtml, $imageFilter = ""): bool
    {
        $imgClass = $this->shadows ? "grafana-img grafana-img-shadows" : "grafana-img";

        if ($this->accessMode === "indirectproxy") {
            if ($this->object instanceof Service) {
                $this->pngUrl = Url::frompath(
                    'grafana/icingadbimg',
                    [
                    'host' => rawurlencode($hostName),
                    'service' => rawurlencode($serviceName),
                    'panelid' => $this->panelId,
                    'timerange' => urlencode($this->timerange),
                    'timerangeto' => urlencode($this->timerangeto),
                    'cachetime' => $this->cacheTime,
                    'imagefilter' => urlencode($imageFilter)
                    ]
                );
            } else {
                $this->pngUrl = Url::frompath(
                    'grafana/icingadbimg',
                    [
                    'host' => rawurlencode($hostName),
                    'panelid' => $this->panelId,
                    'timerange' => urlencode($this->timerange),
                    'timerangeto' => urlencode($this->timerangeto),
                    'cachetime' => $this->cacheTime,
                    'imagefilter' => urlencode($imageFilter)
                    ]
                );
            }

            $imgProps = [
                "src" => Icinga::app()->getViewRenderer()->view->serverUrl() . $this->pngUrl,
                "alt" => $serviceName,
                "width" => $this->width,
                "height" => $this->height,
                "class" => $imgClass
            ];

            $imgHtml = Html::tag('div');
            $imgHtml->addHtml(
                Html::tag(
                    'img',
                    $imgProps
                )
            );

            $previewHtml->add($imgHtml);
        } elseif ($this->accessMode === "iframe") {
            $iFramesrc = sprintf(
                $this->GRAFANA_URL_SOLO,
                $this->protocol,
                $this->grafanaHost,
                $this->dashboarduid,
                $this->dashboard,
                rawurlencode($hostName),
                rawurlencode($serviceName),
                rawurlencode($this->object->checkcommand_name),
                $this->customVars,
                $this->panelId,
                $this->orgId,
                $this->grafanaTheme,
                urlencode($this->timerange),
                urlencode($this->timerangeto)
            );

            $iframeHtml = Html::tag(
                'iframe',
                [
                    "src" => $iFramesrc,
                    "alt" => rawurlencode($serviceName),
                    "height" => $this->height,
                    "class" => "module-grafana-iframe",
                ]
            );

            $previewHtml->add($iframeHtml);
        }

        return true;
    }

    /**
     * @param Model $object
     * @param $report
     * @return ValidHtml
     * @throws ConfigurationError
     * @throws \Icinga\Exception\ProgrammingError
     */
    public function getPreviewHtml(Model $object, $report = false)
    {
        $this->object = $object;
        // Use the cachetime based on the check intervals
        $this->cacheTime = $object->check_interval;

        if ($object instanceof Host) {
            $serviceName = $object->checkcommand_name;
            $hostName = $object->name;
            $link = Links::host($object);
            $parameters = [ 'host' => $hostName ];
        } elseif ($object instanceof Service) {
            $serviceName = $object->name;
            $hostName = $object->host->name;
            $link = Links::service($object, $object->host);
            $parameters = [
                'host' => $hostName,
                'service' => $serviceName,
            ];
        }

        $parameters['timerange'] = $this->timerange;
        $db = $this->getDb();

        $varsFlat = CustomvarFlat::on($db);
        $this->applyRestrictions($varsFlat);

        $varsFlat
            ->columns(['flatname', 'flatvalue'])
            ->orderBy('flatname');

        if ($object instanceof Host) {
            $varsFlat->filter(Filter::equal('host.id', $object->id));
        } else {
            $varsFlat->filter(Filter::equal('service.id', $object->id));
        }

        $customvars = $this->getDb()->fetchPairs($varsFlat->assembleSelect());

        if ($object->perfdata_enabled == "n" || (( isset($customvars[$this->custvardisable]) && json_decode(strtolower($customvars[$this->custvardisable])) !== false))) {
            return '';
        }

        if (array_key_exists($this->custvarconfig, $customvars)
            && !empty($customvars[$this->custvarconfig])) {
            $graphConfiguation = $this->getGraphConf($customvars[$this->custvarconfig]);
        } else {
            $graphConfiguation = $this->getGraphConf($serviceName, $object->checkcommand_name);
        }
        if ($graphConfiguation === null) {
            return HtmlString::create('');
        }

        if ($this->repeatable === "yes") {
            $panelEnd =  ($this->panelId - 1) +
                intval(substr_count($object->state->performance_data, '=') / $this->numberMetrics);
            $this->panelId = implode(
                ',',
                range($this->panelId, $panelEnd)
            );
        }

        // Replace special chars for graphite
        if ($this->dataSource === "graphite" && $this->accessMode !== "indirectproxy") {
            $serviceName = Util::graphiteReplace($serviceName);
            $hostName = Util::graphiteReplace($hostName);
        }

        if (! empty($this->customVars)) {
            // replace template to customVars from Icinga2
            foreach ($customvars as $k => $v) {
                $search[] = "\$$k\$";
                $replace[] = is_string($v) ? $v : null;
                $this->customVars = str_replace($search, $replace, $this->customVars);
            }

            // urlencoded values
            $customVars = "";

            foreach (preg_split('/\&/', $this->customVars, -1, PREG_SPLIT_NO_EMPTY) as $param) {
                $arr = explode("=", $param);
                if (preg_match('/^\$.*\$$/', $arr[1])) {
                    $arr[1] = '';
                }
                if ($this->dataSource === "graphite") {
                    $arr[1] = Util::graphiteReplace($arr[1]);
                }
                $customVars .= '&' . $arr[0] . '=' . rawurlencode(implode('=', array_slice($arr, 1)));
            }
            $this->customVars = $customVars;
        }

        $returnHtml = new HtmlDocument();

        // URL for link to external Grafana
        $url = sprintf(
            $this->GRAFANA_URL,
            $this->publicProtocol,
            $this->publicHost,
            $this->dashboarduid,
            $this->dashboard,
            rawurlencode(($this->dataSource === 'graphite' ? Util::graphiteReplace($hostName) : $hostName)),
            rawurlencode(($this->dataSource === 'graphite' ? Util::graphiteReplace($serviceName) : $serviceName)),
            rawurlencode($object->checkcommand_name),
            $this->customVars,
            urlencode($this->timerange),
            urlencode($this->timerangeto),
            $this->orgId,
            $this->panelId
        );

        // Add a link to Grafana in the title
        $this->title->add(new Link(
            new Icon(
                'arrow-up-right-from-square',
                ['title' => 'View in Grafana']
            ),
            str_replace('/d-solo/', '/d/', $url),
            ['target' => '_blank', 'class' => 'external-link']
        ));

        // Hide menu if in reporting or compact mode
        $menu = "";

        if ($report === false && ! Icinga::app()->getViewRenderer()->view->compact) {
            $timeranges = new Timeranges($parameters, $link);
            $menu = new HtmlString($timeranges->getTimerangeMenu($this->timerange, $this->timerangeto));
        } else {
            $this->title = '';
        }

        foreach (explode(',', $this->panelId) as $panelid) {
            $html = new HtmlDocument();
            $this->panelId = $panelid;

            $flattened_vars = $object->vars;
            // The $object->vars array is flattened, we unflatten the subarray grafanaimagefiltersarray here:
            $i = 0;
            $info = [];
            while (isset($flattened_vars['grafanaimagefiltersarray[' . $i . ']'])) {
                $info[$i] = $flattened_vars['grafanaimagefiltersarray[' . $i . ']'];
                $i++;
            }

            foreach ($info as $value) {
                // The image value will be returned as reference
                $previewHtml = new HtmlDocument();
                $res = $this->getMyPreviewHtml($serviceName, $hostName, $previewHtml, $value);

                if ($res) {
                    $html->addHtml($previewHtml);
                }
            }

            $returnHtml->add($html);
        }

        // Add a data table with runtime information and configuration for debugging purposes
        if (Url::fromRequest()->hasParam('grafanaDebug') && $this->permission->hasPermission('grafana/debug') && $report === false) {
            $returnHtml->addHtml(HtmlElement::create('h2', null, 'Performance Graph Debug'));
            $returnHtml->add($this->createDebugTable());
        }

        $htmlForObject = HtmlElement::create("div", ["class" => "icinga-module module-grafana"]);

        $htmlForObject->add($this->title);
        $htmlForObject->add($menu);
        $htmlForObject->add($returnHtml);
        return $htmlForObject;
    }

    /**
     * createDebugTable creates a data table with runtime information and configuration for debugging purposes
     */
    private function createDebugTable()
    {
        if ($this->accessMode === 'indirectproxy') {
            $usedUrl = $this->pngUrl;
        } else {
            $usedUrl = preg_replace('/.*?src\s*=\s*[\'\"](.*?)[\'\"].*/', "$1", $previewHtml);
        }

        if ($this->accessMode === 'iframe') {
            $this->height = '100%';
        }

        $grafanaTable = HtmlElement::create('table', ['class' => 'name-value-table']);

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Access mode'),
                    Html::tag('td', null, $this->accessMode)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Authentication type'),
                    Html::tag('td', null, $this->authentication)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Protocol'),
                    Html::tag('td', null, $this->protocol)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Grafana Host'),
                    Html::tag('td', null, $this->grafanaHost)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Dashboard UID'),
                    Html::tag('td', null, $this->dashboarduid)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Dashboard Name'),
                    Html::tag('td', null, $this->dashboard)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Panel ID'),
                    Html::tag('td', null, $this->panelId)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Organization ID'),
                    Html::tag('td', null, $this->orgId)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Organization ID'),
                    Html::tag('td', null, $this->orgId)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Theme'),
                    Html::tag('td', null, $this->grafanaTheme)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Timerange'),
                    Html::tag('td', null, $this->timerange)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Timerangeto'),
                    Html::tag('td', null, $this->timerangeto)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Height'),
                    Html::tag('td', null, $this->height)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Width'),
                    Html::tag('td', null, $this->width)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Custom Variables'),
                    Html::tag('td', null, $this->customVars)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Graph URL'),
                    Html::tag('td', null, $usedUrl)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Disable graph custom variable'),
                    Html::tag('td', null, $this->custvardisable)
                ]
            )
        );

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Graph config custom variable'),
                    Html::tag('td', null, $this->custvarconfig)
                ]
            )
        );

        if (isset($customvars[$this->custvarconfig])) {
            $grafanaTable->add(
                Html::tag(
                    'tr',
                    null,
                    [
                        Html::tag('th', null, $this->custvarconfig),
                        Html::tag('td', null, $customvars[$this->custvarconfig])
                    ]
                )
            );
        }

        $grafanaTable->add(
            Html::tag(
                'tr',
                null,
                [
                    Html::tag('th', null, 'Shadows'),
                    Html::tag('td', null, (($this->shadows) ? 'Yes' : 'No'))
                ]
            )
        );

        if ($this->accessMode === "proxy") {
            $grafanaTable->add(
                Html::tag(
                    'tr',
                    null,
                    [
                        Html::tag('th', null, 'SSL Verify Peer'),
                        Html::tag('td', null, (($this->SSLVerifyPeer) ? 'Yes' : 'No'))
                    ]
                )
            );

            $grafanaTable->add(
                Html::tag(
                    'tr',
                    null,
                    [
                        Html::tag('th', null, 'SSL Verify Host'),
                        Html::tag('td', null, (($this->SSLVerifyHost) ? 'Yes' : 'No'))
                    ]
                )
            );
        }

        return $grafanaTable;
    }
}
