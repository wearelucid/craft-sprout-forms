<?php

namespace barrelstrength\sproutforms\integrations\sproutimport\elements;

use barrelstrength\sproutbase\contracts\sproutimport\BaseElementImporter;
use barrelstrength\sproutforms\elements\Form as FormElement;
use barrelstrength\sproutforms\SproutForms;
use barrelstrength\sproutimport\SproutImport;
use Craft;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

class Form extends BaseElementImporter
{
    /**
     * @bool
     */
    public $isNewForm;

    /**
     * @inheritdoc
     */
    public function getModelName()
    {
        return FormElement::class;
    }

    /**
     * @inheritdoc
     */
    public function save()
    {
        return SproutForms::$app->forms->saveForm($this->model);
    }

    /**
     * @inheritdoc
     */
    public function deleteById($id)
    {
        $form = SproutForms::$app->forms->getFormById($id);

        if ($form) {
            SproutForms::$app->forms->deleteForm($form);
        }
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayoutId($model)
    {
        /**
         * @var $model FormElement
         */
        return $model->fieldLayoutId;
    }

    /**
     * @inheritdoc
     */
    public function resolveNestedSettings($model, $settings)
    {
        // Check to see if we have any Entry Types we should also save
        if (empty($settings['settings']['fieldLayout']) OR empty($model->id)) {
            return true;
        }

        Craft::$app->content->fieldContext = $model->fieldContext;
        Craft::$app->content->contentTable = $model->contentTable;

        //------------------------------------------------------------

        // POST DATA FORMAT
        //'id' => '711'
        //'fieldLayout' => [
        //'Section 1' => [
        //    0 => '539'
        //]
        //]
        //'name' => 'Form 1'
        //'titleFormat' => '{dateCreated|date(\'D, d M Y H:i:s\')}'
        //'redirectUri' => ''
        //'submitButtonText' => ''
        //'handle' => 'form1'
        //'displaySectionTitles' => ''

        $fieldLayoutTabs = $settings['settings']['fieldLayout'];
        $fieldLayoutFields = [];
        $requiredFields = [];
        $tabSortOrder = 0;
        $fieldSortOrder = 0;

        $postedFieldLayout = [];

        foreach ($fieldLayoutTabs as $tabName => $fields) {

            $postedFieldLayout[$tabName] = [];

            foreach ($fields as $field) {

                $importerClass = SproutImport::$app->importers->getImporter($field);

                $field = SproutImport::$app->settingsImporter->saveSetting($field, $importerClass);

                if ($field->required)
                {
                    $requiredFields[] = $field->id;
                }

                $field->sortOrder = ++$fieldSortOrder;

                $postedFieldLayout[$tabName][] = $field->id;
            }
        }

        // Create the FieldLayout Class
        $fieldLayout = Craft::$app->fields->assembleLayout($postedFieldLayout, $requiredFields);
        $fieldLayout->type = FormElement::class;

        $model->setFieldLayout($fieldLayout);

        if (!SproutForms::$app->forms->saveForm($model)) {

            SproutForms::error($model->getErrors());

            return false;
        }

        return true;
    }
}