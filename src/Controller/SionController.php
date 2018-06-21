<?php
/**
 * Zend Framework (http://framework.zend.com/)
*
* @link      http://github.com/zendframework/ZendSkeletonModule for the canonical source repository
* @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
* @license   http://framework.zend.com/license/new-bsd New BSD License
*/

namespace SionModel\Controller;

use Zend\View\Model\ViewModel;
use Zend\Mvc\Controller\AbstractActionController;
use SionModel\Db\Model\SionTable;
use SionModel\Service\EntitiesService;
use SionModel;
use SionModel\Form\SionForm;
use SionModel\Entity\Entity;
use SionModel\Form\DeleteEntityForm;
use Zend\Mvc\Plugin\FlashMessenger\FlashMessenger;
use JTranslate\Controller\Plugin\NowMessenger;
use SionModel\Form\TouchForm;
use Zend\View\Model\JsonModel;
use Zend\Form\Form;
use Zend\Mvc\Controller\Plugin\PluginInterface;

class SionController extends AbstractActionController
{
    /**
     * @var Entity[] $entitySpecifications
     */
    protected $entitySpecifications;
    /**
     * @var Entity $entitySpecification
     */
    protected $entitySpecification;
    /**
     * @var SionTable $sionTable
     */
    protected $sionTable;
    /**
    * @var string $entity
    */
    protected $entity;
    /**
    * @var defaultRedirectRoute $defaultRedirectRoute
    */
    protected $defaultRedirectRoute;
    /**
    * @var array $sionModelConfig
    */
    protected $sionModelConfig;

    protected $actionRouteKeys = [
        'show'      => 'showRouteKey',
        'edit'      => 'editRouteKey',
        'delete'    => 'deleteRouteKey',
        'touch'     => 'touchRouteKey',
        'touchJson' => 'touchJsonRouteKey',
    ];

    /**
     * The entity objects that have been requested (normally just one in a page load)
     * @var array $object
     */
    protected $object = [];

    /**
     * The id of the entity in question
     * @var number|string $entityId
     */
    protected $actionEntityIds = [];

    protected $createActionForm;
    
    protected $editActionForm;
    
    /** @var EntitiesService $entitiesService */
    protected $entitiesService;
    
    protected $config;
    
    /**
     * If a SionController needs more services than those provided they can specify these
     * in the 'controller_services' configuration, and they will be injected into this array.
     * @var array $services
     */
    protected $services;
    
    /**
     * @param string $entity
     * @throws \Exception
     */
    public function __construct($entity = null, EntitiesService $entitiesService, SionTable $sionTable, $createActionForm, $editActionForm, array $config, array $services)
    {
        //@todo check the types
        $this->setEntity($entity);
        $this->entitiesService = $entitiesService;
        $this->sionTable = $sionTable;
        $this->createActionForm = $createActionForm;
        $this->editActionForm = $editActionForm;
        $this->config = $config;
        $this->sionModelConfig = $config['sion_model'];
        $this->services = $services;
    }

    public function indexAction()
    {
        /** @var SionTable $table */
        $table      = $this->getSionTable();
        $entity     = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        $objects    = $table->getObjects($entity);
        $view = new ViewModel([
            'entity'    => $entity,
            'entitySpec'=> $entitySpec,
            'objects'   => $objects,
        ]);

        return $view;
    }

    /**
     * Retrieve a requested entityObject by the route parameter specified by the entity's show_route_key.
     * The consumer of the SionController should implement the view template
     * @todo introduce resource-level checks
     * @throws \Exception
     * @return \Zend\View\Model\ViewModel
     */
    public function showAction()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        if (!isset($entitySpec->showRouteKey)) {
            throw new \Exception("Please set the show_route_key config key of $entity in order to use the showAction.");
        }
        $id = (Int)$this->getEntityIdParam('show');
        if (!$id) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                ->addMessage(ucwords($entity).' not found.');
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            return $this->redirect ()->toRoute ( $redirectRoute);
        }

        if (!$this->isActionAllowed('show')) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
            ->addMessage('Access to entity denied.');
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            return $this->redirect ()->toRoute ( $redirectRoute );
        }

        /** @var SionTable $table */
        $table = $this->getSionTable();
        if (!$table->existsEntity($entity, $id)) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        $entityObject = $this->getEntityObject($id);
//         var_dump($entityObject);
        //if the entity doesn't exist, redirect to the index or the default route
        if (!isset($entityObject)) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        $changes = $table->getEntityChanges($entity, $id);

        $table->registerVisit($entity, $entityObject[$entitySpec->entityKeyField]);

        //@todo enable suggest form
//         $sm = $this->getServiceLocator ();
//         /** @var SionForm $suggestForm **/
//         if (!isset($entitySpec->suggestForm)) {
//             $suggestForm = $sm->get('SionModel\Form\SuggestForm');
//         } elseif ($sm->has($entitySpec->suggestForm)) {
//             $suggestForm = $sm->get($entitySpec->suggestForm);
//         } elseif (class_exists($entitySpec->suggestForm)) {
//             $suggestFormName = $entitySpec->suggestForm;
//             $suggestForm = new $suggestFormName;
//         } else {
//             throw new \InvalidArgumentException('Invalid suggest_form specified for \''.$entity.'\' entity.');
//         }

        $view = new ViewModel([
            'entityId'      => $id,
            'entity'        => $entityObject,
            'changes'       => $changes,
//             'suggestForm'   => $suggestForm,
//             'deviceType'    => $deviceType,
        ]);

        //check if the user has the showActionTemplate option set, if not they'll go to the default
        if (isset($entitySpec->showActionTemplate)) {
            $template = $entitySpec->showActionTemplate;
            $view->setTemplate($template);
        }
        return $view;
    }

    public function createAction()
    {
        /** @var SionTable $table **/
        $table = $this->getSionTable();
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();

        if (!isset($entitySpec->createActionForm)) {
            throw new \InvalidArgumentException('If the createAction for \''.$entity.'\' is to be used, it must specify the create_action_form configuration.');
        }
        $form = $this->createActionForm;

        $request = $this->getRequest();
        if ($request->isPost ()) {
            $data = $this->getPostDataForCreateAction();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                //if we have a dataHandler registered, call it @deprecated
                if (isset($entitySpec->createActionValidDataHandler)) {
                    $handlerFunction = $entitySpec->createActionValidDataHandler;
                    if (!method_exists($this, $handlerFunction) ||
                        method_exists('SionController', $handlerFunction)
                    ) {
                        throw new \Exception('Invalid create_action_valid_data_handler set for entity \''.$entity.'\'');
                    }
                    //don't return here so that if the handler doesn't redirect, we send them back to the form
                    $this->$handlerFunction($data, $form);
                } else { //if we have no data handler, we'll do it ourselves
                    $this->createEntityPostFormValidation($data, $form);
                }
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        }
        $view = new ViewModel([
            'form' => $form,
        ]);

        //check if the user has the createActionTemplate option set, if not they'll go to the default
        if (isset($entitySpec->createActionTemplate)) {
            $template = $entitySpec->createActionTemplate;
            $view->setTemplate($template);
        }
        return $view;
    }

    /**
     * Return an array of data to be passed to the setData function of the CreateEntity form
     * @return array
     */
    public function getPostDataForCreateAction()
    {
        return $this->getRequest()->getPost()->toArray();
    }

    /**
     * Creates a new entity, notifies the user via flash messenger and redirects.
     * @param mixed[] $data
     * @param Form $form
     */
    public function createEntityPostFormValidation($data, $form)
    {
        $entity = $this->getEntity();
        $table = $this->getSionTable();
        if (!($newId = $table->createEntity($entity, $data))) {
            $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
        } else {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )
            ->addMessage ( ucwords($entity).' successfully created.' );
            $this->redirectAfterCreate((int) $newId);
        }
    }

    /**
     * This function is called after a successful entity creation to redirect the user.
     * May be overwritten by a child Controller to add functionality.
     * @param int $newId
     * @throws \Exception
     */
    public function redirectAfterCreate($newId)
    {
        /** @var SionTable $table **/
        $table = $this->getSionTable();
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();

        //check if user has the redirect route set
        if (isset($entitySpec->createActionRedirectRoute)) {
            if (!isset($entitySpec->createActionRedirectRouteKeyField) ||
                $entitySpec->createActionRedirectRouteKeyField == $entitySpec->entityKeyField ||
                !isset($entitySpec->createActionRedirectRouteKey)
            ) {
                $this->redirect ()->toRoute ($entitySpec->createActionRedirectRoute,
                    isset($entitySpec->createActionRedirectRouteKey) ?
                    [$entitySpec->createActionRedirectRouteKey => $newId] : []);
            } else {
                $entityObj = $table->getObject($entity, $newId);
                if (!isset($entityObj[$entitySpec->createActionRedirectRouteKeyField])) {
                    throw new \Exception('create_action_redirect_route_key_field is misconfigured for entity \''.$entity.'\'');
                }
                $this->redirect ()->toRoute ($entitySpec->createActionRedirectRoute,
                    [$entitySpec->createActionRedirectRouteKey => $entityObj[$entitySpec->createActionRedirectRouteKeyField]]);
            }
        } else {
            $this->redirect ()->toRoute ($this->getDefaultRedirectRoute());
        }
    }

    /**
     * Standard edit action which checks edit_route_key input, looks up the entity,
     * checks for a post, validates the data with the form and submits the change if the form validates.
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @return ViewModel
     */
    public function editAction()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        if (!isset($entitySpec->editRouteKey)) {
            throw new \Exception("Please set the edit_route_key config key of $entity in order to use the editAction.");
        }
        $id = (Int)$this->getEntityIdParam('edit');
        //if the entity doesn't exist, redirect to the index or the default route
        if (! $id) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute);
        }
        $entityObject = $this->getEntityObject($id);
        //if the entity doesn't exist, redirect to the index or the default route
        if (!isset($entityObject)) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        if (!$this->isActionAllowed('edit')) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
            ->addMessage('Access to entity denied.');
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        if (!isset($this->editActionForm)) {
            throw new \InvalidArgumentException('If the editAction for \''.$entity.'\' is to be used, it must specify the edit_action_form configuration.');
        }
        $form = $this->editActionForm;

        $request = $this->getRequest ();
        if ($request->isPost ()) {
            $data = $this->getPostDataForEditAction();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $this->updateEntityPostFormValidation($id, $data, $form);
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($entityObject);
        }
        $deleteForm = new DeleteEntityForm();
        $view = new ViewModel([
            'entity'    => $entityObject,
            'entityId'  => $id,
            'form'      => $form,
            'deleteForm'=> $deleteForm,
        ]);

        //check if the user has the editActionTemplate option set, if not they'll go to the default
        if (isset($entitySpec->editActionTemplate)) {
            $template = $entitySpec->editActionTemplate;
            $view->setTemplate($template);
        }
        return $view;
    }

    /**
     * Return an array of data to be passed to the setData function of the EditEntity form
     * @return array
     */
    public function getPostDataForEditAction()
    {
        return $this->getRequest()->getPost()->toArray();
    }

    /**
     * Updates a given entity, notifies the user via flash messenger and calls the redirect function.
     * @param int $id
     * @param mixed[] $data
     * @param Form $form
     * @throws \Exception
     */
    public function updateEntityPostFormValidation($id, $data, $form)
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        /** @var SionTable $table **/
        $table = $this->getSionTable();
        $table->updateEntity($entity, $id, $data);
        $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( ucfirst($entity).' successfully updated.' );
        $this->redirectAfterEdit($id);
    }

    /**
     * Redirects the user after successfully editing an entity.
     * @param int $id
     * @throws \Exception
     */
    public function redirectAfterEdit($id)
    {
        $entitySpec = $this->getEntitySpecification();
        $entityObject = $this->getEntityObject($id);
        if ($entitySpec->showRouteKey && $entitySpec->showRouteKey &&
            $entitySpec->showRouteKeyField
        ) {
            if (!isset($entityObject[$entitySpec->showRouteKeyField])) {
                throw new \Exception("show_route_key_field config for entity '$entity' refers to a key that doesn't exist");
            }
            $this->redirect ()->toRoute ($entitySpec->showRoute,
                    [$entitySpec->showRouteKey => $entityObject[$entitySpec->showRouteKeyField]]);
        } elseif ($entitySpec->indexRoute) {
            $this->redirect ()->toRoute ($entitySpec->indexRoute);
        } else {
            $this->redirect ()->toRoute ( $this->getDefaultRedirectRoute() );
        }
    }

    /**
     * @todo test!
     * @throws \Exception
     * @return \Zend\View\Model\ViewModel
     */
    public function touchAction()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        $id = (Int)$this->getEntityIdParam('touch');
        //if the entity doesn't exist, redirect to the index or the default route
        if (! $id) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute);
        }
        $entityObject = $this->getEntityObject($id);
        //if the entity doesn't exist, redirect to the index or the default route
        if (!isset($entityObject)) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        $form = new TouchForm();

        $request = $this->getRequest ();
        if ($request->isPost ()) {
            $data = $request->getPost ()->toArray ();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                /** @var SionTable $table **/
                $table = $this->getSionTable();
                $fieldToTouch = $this->whichFieldToTouch();
                $table->touchEntity($entity, $id, $fieldToTouch);
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( ucfirst($entity).' successfully marked up-to-date.' );
                if ($entitySpec->showRouteKey && $entitySpec->showRouteKey &&
                    $entitySpec->showRouteKeyField
                ) {
                    if (!isset($entityObject[$entitySpec->showRouteKeyField])) {
                        throw new \Exception("show_route_key_field config for entity '$entity' refers to a key that doesn't exist");
                    }
                    $this->redirect ()->toRoute ($entitySpec->showRoute,
                        [$entitySpec->showRouteKey => $entityObject[$entitySpec->showRouteKeyField]]);
                } else {
                    $this->redirect ()->toRoute ( $this->getDefaultRedirectRoute() );
                }
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($entityObject);
        }
        return new ViewModel([
            'entity'    => $entityObject,
            'entityId'  => $id,
            'form'      => $form,
        ]);
    }

    /**
     * Touch the entity, and return the status through the HTTP code
     * @return \Zend\Stdlib\ResponseInterface|\SionModel\Controller\JsonModel
     */
    public function touchJsonAction()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();

        $id = (Int)$this->getEntityIdParam('touchJson');
        if (!isset($id)) {
            return $this->sendFailedMessage('Invalid id passed.');
        }
        $callback = $this->params ()->fromQuery ('callback', null);
        if (!isset($callback)) {
            return $this->sendFailedMessage('All requests must include a callback function set as a query parameter \'callback\'.');
        }

        $form = new TouchForm();

        $request = $this->getRequest();
        if (!$request->isPost ()) {
            return $this->sendFailedMessage('Please use post method.');
        }
        //$data = Json::decode($request->getContent(), Json::TYPE_ARRAY);
        $data = $request->getPost()->toArray();
        $form->setData($data);
        if (!$form->isValid()) {
            return $this->sendFailedMessage('The following fields are invalid: '.
                implode(', ', array_keys($form->getInputFilter()->getInvalidInput())).
                    $request->getContent());
        }

        /** @var SionTable $table */
        $table = $this->getSionTable();
        $fieldToTouch = $this->whichFieldToTouch();
        $return = $table->touchEntity($entity, $id, $fieldToTouch);

        $view = new JsonModel([
            'return'    => $return,
            'field'     => $fieldToTouch,
            'message'   => 'Success',
        ]);
        $view->setJsonpCallback($callback);
        return $view;
    }

    /**
     * If the form has been posted, confirm the CSRF. If all is well, delete the entity.
     * If the request is a GET, ask the user to confirm the deletion
     * @return \Zend\View\Model\ViewModel
     *
     * @todo Create a view template to ask for confirmation
     * @todo check if client expects json, and make it AJAX friendly
     */
    public function deleteAction()
    {
        $entity = $this->getEntity();
        $id = (Int)$this->getEntityIdParam('delete');
        $entitySpec = $this->getEntitySpecification();

        $request = $this->getRequest();

        //make sure we have all the information that we need to delete
        if (!$entitySpec->isEnabledForEntityDelete()) {
            $this->flashMessenger()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )
            ->addMessage ( 'This entity cannot be deleted, please check the configuration.');
            $this->redirectAfterDelete(false);
        }

        //make sure the user has permission to delete the entity
        if (!$this->isActionAllowed('delete')) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
            ->addMessage('You do not have permission to delete this entity.');
            $this->redirectAfterDelete(false);
        }

        //@deprecated @todo remove this part
        if (isset($entitySpec->deleteActionAclResource) &&
            !$this->isAllowed($entitySpec->deleteActionAclResource,
                $entitySpec->deleteActionAclPermission ?
                $entitySpec->deleteActionAclPermission : null)
        ) {
            $this->flashMessenger()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )
            ->addMessage ( 'You do not have permission to delete this entity.');
            $this->redirectAfterDelete(false);
        }

        //make sure our table exists
        $table = $this->getSionTable();

        //make sure our entity exists
        if (!$table->existsEntity($entity, $id)) {
            $this->getResponse()->setStatusCode(401);
            $this->flashMessenger()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )
                ->addMessage ( 'The entity you\'re trying to delete doesn\'t exists.' );
            $this->redirectAfterDelete(false);
        }

        $form = new DeleteEntityForm();
        if ( $request->isPost ()) {
            $data = $request->getPost();
            $form->setData($data);
            if ($form->isValid()) {
                $result = $table->deleteEntity($entity, $id);
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )
                ->addMessage ( 'Entity successfully deleted.' );
                $this->redirectAfterDelete(true);
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                $this->getResponse()->setStatusCode(401); //exists, but either didn't match params or bad csrf
            }
        }

        //set the form action url
        $form->setAttribute('action', $this->getRequest()->getRequestUri());
        $entityObject = $this->getEntityObject($id);

        $view = new ViewModel ( [
            'form' => $form,
            'entity' => $entity,
            'entityId' => $id,
            'entityObject' => $entityObject,
        ] );
        //@todo first check if the Controller has a default template, if not
        //look for a configuration value, if not, use the default template
        $view->setTemplate('sion-model/sion-model/delete');
        return $view;
    }

    /**
     * Called in order to redirect after error or success on deleteAction
     * @param bool $actionWasSuccessful
     * @throws \Exception
     */
    protected function redirectAfterDelete($actionWasSuccessful = true)
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        if (isset($entitySpec->deleteActionRedirectRoute)) {
            $this->redirect()->toRoute($entitySpec->deleteActionRedirectRoute);
        } else {
            if (null === ($defaultRedirectRoute = $this->getDefaultRedirectRoute())) {
                throw new \Exception("Please configure the deleteActionRedirectRoute for $entity, or the sion_model default_redirect_route.");
            }
            $this->redirect()->toRoute($defaultRedirectRoute);
        }
    }

    protected function isActionAllowed($action)
    {
        if (!isset(Entity::$isActionAllowedPermissionProperties[$action])) {
            throw new \InvalidArgumentException('Invalid action parameter');
        }
        $entityId = $this->getEntityIdParam($action);
        $entitySpec = $this->getEntitySpecification();

        if (!isset($entitySpec->aclResourceIdField)) {
            return true;
        }

        /**
         * isAllowed plugin
         * @var PluginInterface $isAllowedPlugin
         */
        $isAllowedPlugin = null;
        try {
            $isAllowedPlugin = $this->plugin('isAllowed');
        } catch (Exception $e) {
        }
        //if we don't have the isAllowed plugin, just allow
        if (!is_callable($isAllowedPlugin)) {
            return true;
        }

        $permissionProperty = Entity::$isActionAllowedPermissionProperties[$action];
        $object = $this->getEntityObject($entityId);
        if (!isset($entitySpec->$permissionProperty)) {
            //we don't need the permission, just the resourceId
            return $isAllowedPlugin->__invoke($object[$entitySpec->aclResourceIdField]);
        }

        return $isAllowedPlugin->__invoke($object[$entitySpec->aclResourceIdField], $entitySpec->$permissionProperty);
    }

    /**
     * Get the value of the entityId route parameter given the action. If no parameter is
     * set in the Request, $default is returned
     * @param string $action
     * @param mixed $default
     * @throws \Exception
     * @return mixed
     */
    protected function getEntityIdParam($action = 'show', $default = null)
    {
        if (isset($this->actionEntityIds[$action])) {
            return $this->actionEntityIds[$action];
        }
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        $actionRouteKey = null;
        if (isset($this->actionRouteKeys[$action])) {
            $actionRouteKey = $this->actionRouteKeys[$action];
            if (isset($entitySpec->$actionRouteKey)) {
                return $this->actionEntityIds[$action] =
                    $this->params()->fromRoute($entitySpec->$actionRouteKey, $default);
            }
        } else {
            $actionRouteKey = 'defaultRouteKey';
        }
        if (isset($entitySpec->defaultRouteKey)) {
            return $this->actionEntityIds[$action] =
                $this->params()->fromRoute($entitySpec->defaultRouteKey, $default);
        }
        throw new \Exception("Please configure the $actionRouteKey for the $entity entity to use the $action action.");
    }

    protected function sendFailedMessage($message)
    {
        $response = $this->getResponse();
        $response->setStatusCode(401);
        $response->sendHeaders();
        $response->setContent($message);
        return $response;
    }

    /**
     * Election rules:
     * 1. If there is a route parameter to specify the field, and the field exists, use it.
     * 2. Else, if the touchDefaultField exists, use it.
     * 3. Else, touch the entityKeyField if it exists
     */
    protected function whichFieldToTouch()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        $touchField = null;
        if (isset($entitySpec->touchFieldRouteKey)) {
            $touchField = $this->params ()->fromRoute ($entitySpec->touchFieldRouteKey);
            if (isset($entitySpec->updateColumns[$touchField])) {
                return $touchField;
            }
        }
        if (isset($entitySpec->touchDefaultField) && isset($entitySpec->updateColumns[$entitySpec->touchDefaultField])) {
            return $entitySpec->touchDefaultField;
        }
        if (isset($entitySpec->entityKeyField) && isset($entitySpec->updateColumns[$entitySpec->entityKeyField])) {
            return $entitySpec->entityKeyField;
        }
        throw new \Exception("Cannot find a field to touch for entity '$entity'");
    }

    /**
     * Get the entitySpecification value
     * @return Entity
     */
    public function getEntitySpecification()
    {
        if (!isset($this->entitySpecification)) {
            $entity = $this->getEntity();
            $entities = $this->getEntitySpecifications();
            if (!isset($entities[$entity])) {
                throw new \Exception('Invalid entity given\''.$entity.'\'');
            }
            $this->setEntitySpecification($entities[$entity]);
        }
        return $this->entitySpecification;
    }

    /**
     *
     * @param Entity $entitySpecification
     * @return self
     */
    public function setEntitySpecification(Entity $entitySpecification)
    {
        $this->entitySpecification = $entitySpecification;
        return $this;
    }

    /**
     * Get the array of entity specifications from config
     * @return \SionModel\Entity\Entity[]
     */
    public function getEntitySpecifications()
    {
        if (!isset($this->entitySpecifications)) {
            $entitiesService = $this->entitiesService;
            $this->entitySpecifications = $entitiesService->getEntities();
        }
        return $this->entitySpecifications;
    }

    public function getEntityObject($id)
    {
        if (isset($this->object[$id])) {
            return $this->object[$id];
        }
        $table = $this->getSionTable();
        return $this->object[$id] = $table->getObject($this->getEntity(), $id, true);
    }

    /**
     * Get the sionTable value
     * @return SionTable
     */
    public function getSionTable()
    {
        if (!isset($this->sionTable)) {
            throw new \Exception('Invalid SionModel class set for entity \''.$this->getEntity().'\'');
        }
        return $this->sionTable;
    }

    /**
     * @param SionTable $sionTable
     * @return self
     */
    public function setSionTable($sionTable)
    {
        if (!$sionTable instanceof SionTable) {
            throw new \Exception('Expecting SionModelClass to be a SionTable instance.');
        }
        $this->sionTable = $sionTable;
        return $this;
    }

    /**
     * Get the entity value
     * @return string
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     *
     * @param string $entity
     * @return self
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;
        return $this;
    }

    /**
     * Get the defaultRedirectRoute value
     * @return defaultRedirectRoute
     */
    public function getDefaultRedirectRoute()
    {
        if (!isset($this->defaultRedirectRoute)) {
            $config = $this->getSionModelConfig();
            $redirectRoute = $config['default_redirect_route'];
            $this->setDefaultRedirectRoute($redirectRoute);
        }
        return $this->defaultRedirectRoute;
    }

    /**
     *
     * @param defaultRedirectRoute $defaultRedirectRoute
     * @return self
     */
    public function setDefaultRedirectRoute($defaultRedirectRoute)
    {
        $this->defaultRedirectRoute = $defaultRedirectRoute;
        return $this;
    }

    /**
     * Get the sionModelConfig value
     * @return array
     */
    public function getSionModelConfig()
    {
        if (!isset($this->sionModelConfig)) {
            throw new \Exception('Something went wrong, no sion model config available');
        }
        return $this->sionModelConfig;
    }

    /**
     *
     * @param array $sionModelConfig
     * @return self
     */
    public function setSionModelConfig(array $sionModelConfig)
    {
        $this->sionModelConfig = $sionModelConfig;
        return $this;
    }
}