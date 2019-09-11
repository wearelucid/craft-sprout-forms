<?php

namespace barrelstrength\sproutforms\controllers;

use barrelstrength\sproutforms\elements\Entry;
use barrelstrength\sproutforms\events\OnBeforeValidateEntryEvent;
use Craft;
use craft\errors\MissingComponentException;
use craft\web\Controller as BaseController;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

use barrelstrength\sproutforms\SproutForms;
use barrelstrength\sproutforms\elements\Form as FormElement;
use barrelstrength\sproutforms\elements\Entry as EntryElement;
use barrelstrength\sproutforms\events\OnBeforePopulateEntryEvent;
use yii\web\Response;

/**
 *
 * @property null|EntryElement $entryModel
 */
class EntriesController extends BaseController
{
    const EVENT_BEFORE_POPULATE = 'beforePopulate';
    const EVENT_BEFORE_VALIDATE = 'beforeValidate';

    /**
     * Allows anonymous execution
     *
     * @var string[]
     */
    protected $allowAnonymous = [
        'save-entry'
    ];

    /**
     * @var FormElement
     */
    public $form;

    public function init()
    {
        parent::init();

        $response = Craft::$app->getResponse();
        $headers = $response->getHeaders();
        $headers->set('Cache-Control', 'private');
    }

    /**
     * Processes form submissions
     *
     * @return null|Response
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws \Exception
     * @throws Throwable
     * @throws BadRequestHttpException
     */
    public function actionSaveEntry()
    {   
        // TODO: Add config/sproutforms.php file to add allowed origins in settings!
        // $origin = \Craft::$app->getRequest()->headers->get('Origin');
        // $allowed_domains = [
        //   'http://localhost:3000',
        //   'https://www.mysite2.com',
        //   'http://www.mysite2.com',
        // ];
        
        // For now: Set wildcard for CORS
        header('Access-Control-Allow-Origin: *');

        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        if ($request->getIsCpRequest()) {
            $this->requirePermission('sproutForms-editEntries');
        }

        $formHandle = $request->getRequiredBodyParam('handle');
        $this->form = SproutForms::$app->forms->getFormByHandle($formHandle);

        if ($this->form === null) {
            throw new Exception('No form exists with the handle '.$formHandle);
        }

        $event = new OnBeforePopulateEntryEvent([
            'form' => $this->form
        ]);

        $this->trigger(self::EVENT_BEFORE_POPULATE, $event);

        $entry = $this->getEntryModel();

        Craft::$app->getContent()->populateElementContent($entry);

        $statusId = $request->getBodyParam('statusId');

        if ($statusId !== null) {
            $entry->statusId = $statusId;
        }

        // Populate the entry with post data
        $this->populateEntryModel($entry);

        $entry->statusId = $entry->statusId != null
            ? $entry->statusId
            : SproutForms::$app->entries->getDefaultEntryStatusId();

        // Render the Entry Title
        try {
            $entry->title = Craft::$app->getView()->renderObjectTemplate($this->form->titleFormat, $entry);
        } catch (\Exception $e) {
            Craft::error('Title format error: '.$e->getMessage(), __METHOD__);
        }

        $event = new OnBeforeValidateEntryEvent([
            'form' => $this->form,
            'entry' => $entry
        ]);

        $this->trigger(self::EVENT_BEFORE_VALIDATE, $event);

        $success = $entry->validate();

        if (!$success) {
            Craft::error($entry->getErrors(), __METHOD__);
            return $this->redirectWithErrors($entry);
        }

        return $this->saveEntryInCraft($entry);
    }

    /**
     * @param EntryElement $entry
     *
     * @return null|Response
     * @throws Exception
     * @throws \Exception
     * @throws Throwable
     * @throws BadRequestHttpException
     */
    private function saveEntryInCraft(Entry $entry)
    {
        $success = true;

        $saveData = SproutForms::$app->entries->isSaveDataEnabled($this->form);

        // Save Data and Trigger the onSaveEntryEvent
        if ($saveData) {
            $success = SproutForms::$app->entries->saveEntry($entry);
        } else {
            $isNewEntry = !$entry->id;

            SproutForms::$app->entries->callOnSaveEntryEvent($entry, $isNewEntry);
        }

        if (!$success) {
            return $this->redirectWithErrors($entry);
        }

        $this->createLastEntryId($entry);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson([
                'success' => true
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('sprout-forms', 'Entry saved.'));

        return $this->redirectToPostedUrl($entry);
    }

    /**
     * Route Controller for Edit Entry Template
     *
     * @param int|null          $entryId
     * @param EntryElement|null $entry
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws MissingComponentException
     * @throws InvalidConfigException
     */
    public function actionEditEntry(int $entryId = null, EntryElement $entry = null): Response
    {
        $this->requirePermission('sproutForms-editEntries');

        if (SproutForms::$app->forms->activeCpEntry) {
            $entry = SproutForms::$app->forms->activeCpEntry;
        } else {
            if ($entry === null) {
                $entry = SproutForms::$app->entries->getEntryById($entryId);
            }

            if (!$entry) {
                throw new NotFoundHttpException('Entry not found');
            }

            Craft::$app->getContent()->populateElementContent($entry);
        }

        $form = SproutForms::$app->forms->getFormById($entry->formId);

        $saveData = SproutForms::$app->entries->isSaveDataEnabled($form);

        if (!$saveData) {
            Craft::$app->getSession()->setError(Craft::t('sprout-forms', "Unable to edit entry. Enable the 'Save Data' for this form to view, edit, or delete content."));

            return $this->renderTemplate('sprout-forms/entries');
        }

        $entryStatus = SproutForms::$app->entries->getEntryStatusById($entry->statusId);
        $statuses = SproutForms::$app->entries->getAllEntryStatuses();
        $entryStatuses = [];

        foreach ($statuses as $key => $status) {
            $entryStatuses[$status->id] = $status->name;
        }

        $variables['form'] = $form;
        $variables['entryId'] = $entryId;
        $variables['entryStatus'] = $entryStatus;
        $variables['statuses'] = $entryStatuses;

        // This is our element, so we know where to get the field values
        $variables['entry'] = $entry;

        // Get the fields for this entry
        $fieldLayoutTabs = $entry->getFieldLayout()->getTabs();

        $tabs = [];

        foreach ($fieldLayoutTabs as $tab) {
            $tabs[$tab->id]['label'] = $tab->name;
            $tabs[$tab->id]['url'] = '#tab'.$tab->sortOrder;
        }

        $variables['tabs'] = $tabs;
        $variables['fieldLayoutTabs'] = $fieldLayoutTabs;

        return $this->renderTemplate('sprout-forms/entries/_edit', $variables);
    }

    /**
     * @return Response
     * @throws Throwable
     * @throws BadRequestHttpException
     */
    public function actionDeleteEntry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('sproutForms-editEntries');

        $request = Craft::$app->getRequest();

        // Get the Entry
        $entryId = $request->getRequiredBodyParam('entryId');

        Craft::$app->elements->deleteElementById($entryId);

        return $this->redirectToPostedUrl();
    }

    /**
     * Populate a EntryElement with post data
     *
     * @access private
     *
     * @param EntryElement $entry
     */
    private function populateEntryModel(EntryElement $entry)
    {
        $request = Craft::$app->getRequest();

        // Our EntryElement requires that we assign it a FormElement id
        $entry->formId = $this->form->id;
        $entry->ipAddress = $request->getUserIP();
        $entry->userAgent = $request->getUserAgent();

        // Set the entry attributes, defaulting to the existing values for whatever is missing from the post data
        $fieldsLocation = $request->getBodyParam('fieldsLocation', 'fields');

        $entry->setFieldValuesFromRequest($fieldsLocation);
        $entry->setFieldParamNamespace($fieldsLocation);
    }

    /**
     * Fetch or create a EntryElement class
     *
     * @return EntryElement
     * @throws Exception
     */
    private function getEntryModel(): EntryElement
    {
        $entryId = null;
        $request = Craft::$app->getRequest();

        /** @var SproutForms $plugin */
        $plugin = Craft::$app->getPlugins()->getPlugin('sprout-forms');
        $settings = $plugin->getSettings();

        if ($request->getIsCpRequest() || $settings->enableEditFormEntryViaFrontEnd) {
            $entryId = $request->getBodyParam('entryId');
        }

        if (!$entryId) {
            return new EntryElement();
        }

        $entry = SproutForms::$app->entries->getEntryById($entryId);

        if (!$entry) {
            $message = Craft::t('sprout-forms', 'No form entry exists with the given ID: {id}', [
                'entryId' => $entryId
            ]);
            throw new Exception($message);
        }

        return $entry;
    }

    /**
     * @param EntryElement $entry
     *
     * @return null|Response
     * @throws BadRequestHttpException
     * @throws MissingComponentException
     */
    private function redirectWithErrors(Entry $entry)
    {
        // Allow override of redirect URL on failure
        if (Craft::$app->getRequest()->getBodyParam('redirectOnFailure') !== '') {
            $_POST['redirect'] = Craft::$app->getRequest()->getBodyParam('redirectOnFailure');
        }

        Craft::error($entry->getErrors(), __METHOD__);

        // Send spam to the thank you page
        if (SproutForms::$app->entries->fakeIt) {
            return $this->redirectToPostedUrl($entry);
        }

        // Handle CP requests in a CP-friendly way
        if (Craft::$app->getRequest()->getIsCpRequest()) {

            Craft::$app->getSession()->setError(Craft::t('sprout-forms', 'Couldn’t save entry.'));

            // Store this Entry Model in a variable in our Service layer so that
            // we can access the error object from our actionEditEntryTemplate() method
            SproutForms::$app->forms->activeCpEntry = $entry;

            Craft::$app->getUrlManager()->setRouteParams([
                'entry' => $entry
            ]);

            return null;
        }

        // Respond to ajax requests with JSON
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'errors' => $entry->getErrors(),
            ]);
        }

        // Front-end Requests need to be a bit more dynamic

        // Store this Entry Model in a variable in our Service layer so that
        // we can access the error object from our displayForm() variable
        SproutForms::$app->forms->activeEntries[$this->form->handle] = $entry;

        // Return the form using it's name as a variable on the front-end
        Craft::$app->getUrlManager()->setRouteParams([
            $this->form->handle => $entry
        ]);

        return null;
    }

    /**
     * @param $entry
     *
     * @throws MissingComponentException
     */
    private function createLastEntryId($entry)
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            // Store our new entry so we can recreate the Entry object on our thank you page
            Craft::$app->getSession()->set('lastEntryId', $entry->id);
        }
    }
}
