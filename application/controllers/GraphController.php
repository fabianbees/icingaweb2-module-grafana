<?php

namespace Icinga\Module\Grafana\Controllers;

use Icinga\Module\Grafana\Forms\Graph\GraphForm;

use Icinga\Exception\NotFoundError;
use Icinga\Forms\ConfirmRemovalForm;
use Icinga\Web\Controller;
use Icinga\Web\Notification;

/**
 * GraphController for the graphs configuration table and forms.
 */
class GraphController extends Controller
{
    public function init()
    {
        $this->assertPermission('grafana/graphconfig');
    }

    /**
     * List Grafana graphs
     */
    public function indexAction()
    {
        $this->view->tabs = $this->Module()->getConfigTabs()->activate('graph');
        $this->view->graphs = $this->Config('graphs');
    }

    /**
     * Add a new graph
     */
    public function newAction()
    {
        $this->getTabs()->add('new-graph', [
            'active' => true,
            'label' => $this->translate('Add graph'),
            'url' => $this->getRequest()->getUrl()
        ]);

        $graphs = new GraphForm();

        $graphs->setIniConfig($this->Config('graphs'))
            ->setRedirectUrl('grafana/graph')
            ->handleRequest();

        $this->view->form = $graphs;
    }

    /**
     * Remove a graph
     */
    public function removeAction()
    {
        $graph = $this->params->getRequired('graph');
        $this->getTabs()->add('remove-graph', [
            'active' => true,
            'label' => $this->translate('Remove graph'),
            'url' => $this->getRequest()->getUrl()
        ]);

        $graphs = new GraphForm();

        try {
            $graphs->setIniConfig($this->Config('graphs'))->bind($graph);
        } catch (NotFoundError $e) {
            $this->httpNotFound($e->getMessage());
        }

        $confirmation = new ConfirmRemovalForm([
            'onSuccess' => function (ConfirmRemovalForm $confirmation) use ($graph, $graphs) {
                $graphs->remove($graph);
                if ($graphs->save()) {
                    Notification::success(mt('grafana', 'Graph removed'));
                    return true;
                }
                return false;
            }
        ]);

        $confirmation->setRedirectUrl('grafana/graph')
            ->setSubmitLabel($this->translate('Remove graph'))
            ->handleRequest();

        $this->view->form = $confirmation;
    }

    /**
     * Update a graph
     */
    public function updateAction()
    {
        $graph = $this->params->getRequired('graph');
        $this->getTabs()->add('update-graph', [
            'active' => true,
            'label' => $this->translate('Update graph'),
            'url' => $this->getRequest()->getUrl()
        ]);

        $graphs = new GraphForm();

        try {
            $graphs->setIniConfig($this->Config('graphs'))->bind($graph);
        } catch (NotFoundError $e) {
            $this->httpNotFound($e->getMessage());
        }

        $graphs->setRedirectUrl('grafana/graph')->handleRequest();

        $this->view->form = $graphs;
    }
}
